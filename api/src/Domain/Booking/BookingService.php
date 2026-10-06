<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Domain\Availability\SlotEngine;
use ConsultDesk\Domain\Availability\SlotRequest;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Db;
use DateTimeImmutable;
use RuntimeException;

/**
 * Creates and moves bookings through their lifecycle (docs/PLAN.md §5).
 *
 * Every write locks the provider or booking row first and reads the clock after the lock, so
 * concurrent requests are serialised and decisions use the current time. Side effects (calendar,
 * email, Telegram) are not done here; later phases add them through the outbox.
 *
 * Free services that do not require approval are held and then confirmed straight away by the
 * caller (Phase 2); only services that require approval wait in `held` for a provider.
 */
final class BookingService
{
    private const MAX_REF_ATTEMPTS = 5;

    public function __construct(
        private readonly Db $db,
        private readonly BookingRepository $bookings,
        private readonly Clock $clock,
        private readonly RefGenerator $refs,
        private readonly SlotEngine $slotEngine = new SlotEngine(),
    ) {}

    /**
     * Reserves a slot while the customer pays or awaits approval.
     *
     * Under the provider lock, the requested start must be a slot SlotEngine offers right now:
     * inside the weekly hours, on the slot grid, outside blocked periods, within notice and horizon,
     * clear of other bookings by the gap and under the daily cap.
     *
     * @throws ServiceNotBookable|PaymentMethodNotAllowed|SlotUnavailable|DailyLimitReached
     */
    public function hold(HoldRequest $request): HeldBooking
    {
        return $this->db->transaction(function () use ($request): HeldBooking {
            $provider = $this->bookings->lockActiveProvider($request->providerId) ?? throw new ServiceNotBookable();
            $now = $this->clock->now();
            $service = $this->bookings->findActiveService($request->serviceId);
            if ($service === null || $service->providerId !== $provider->id) {
                throw new ServiceNotBookable();
            }
            $this->assertPaymentMethod($service, $request->paymentMethod);

            $slot = new Interval($request->start, $request->start->modify(sprintf('+%d minutes', $service->durationMinutes)));
            $this->assertOffered($provider, $service, $slot, $now);

            $token = $this->refs->token();
            $holdExpiresAt = min($now->modify(sprintf('+%d minutes', HoldPolicy::holdMinutes($request->paymentMethod))), $slot->start);
            [$id, $ref] = $this->insertWithUniqueRef(new NewBooking(
                $this->refs->next(),
                RandomRefGenerator::hashToken($token),
                $provider->id,
                $service->id,
                $slot,
                $request->customer,
                $request->answers,
                $service->priceMinor,
                $service->currency,
                $request->paymentMethod,
                $holdExpiresAt,
                $now,
            ));

            $this->bookings->audit(Actor::customer(), 'booking.held', $id, [
                'ref' => $ref,
                'start_at' => $slot->start->format(DATE_ATOM),
                'payment_method' => $request->paymentMethod->value,
            ], $now);

            return new HeldBooking($id, $ref, $token, $holdExpiresAt);
        });
    }

    /**
     * Records the customer's UPI reference and gives the provider up to a day to verify it,
     * but never past the start of the session.
     *
     * @throws InvalidUtr|BookingNotFound|PaymentMethodNotAllowed|HoldExpired|InvalidTransition|DuplicateUtr
     */
    public function submitUtr(int $bookingId, string $utr): void
    {
        $validUtr = new Utr($utr);

        $this->db->transaction(function () use ($bookingId, $validUtr): void {
            $booking = $this->lockBooking($bookingId);
            $now = $this->clock->now();
            if ($booking->paymentMethod !== PaymentMethod::Upi) {
                throw new PaymentMethodNotAllowed();
            }
            $this->assertHoldLive($booking, $now);
            StatusMachine::assertTransition($booking->status, BookingStatus::AwaitingVerification, $booking->paymentMethod);

            $verifyBy = min($now->modify(sprintf('+%d minutes', HoldPolicy::VERIFICATION_WINDOW_MINUTES)), $booking->startAt);
            $this->bookings->markAwaitingVerification($bookingId, $validUtr, $verifyBy, $now);
            $this->bookings->audit(Actor::customer(), 'booking.utr_submitted', $bookingId, ['utr' => $validUtr->value], $now);
        });
    }

    /**
     * @throws BookingNotFound|HoldExpired|InvalidTransition
     */
    public function confirm(int $bookingId, Actor $actor): void
    {
        $this->db->transaction(function () use ($bookingId, $actor): void {
            $booking = $this->lockBooking($bookingId);
            $now = $this->clock->now();
            StatusMachine::assertTransition($booking->status, BookingStatus::Confirmed, $booking->paymentMethod);
            // A lapsed hold may already have been rebooked by someone else.
            $this->assertHoldLive($booking, $now);

            $this->bookings->markConfirmed($bookingId, $actor->type === ActorType::User ? $actor->id : null, $now);
            $this->bookings->audit($actor, 'booking.confirmed', $bookingId, [], $now);
        });
    }

    public function reject(int $bookingId, Actor $actor): void
    {
        $this->changeStatus($bookingId, BookingStatus::Rejected, $actor);
    }

    public function cancel(int $bookingId, Actor $actor): void
    {
        $this->changeStatus($bookingId, BookingStatus::Cancelled, $actor);
    }

    /**
     * @throws SessionNotStarted
     */
    public function complete(int $bookingId, Actor $actor): void
    {
        $this->changeStatus($bookingId, BookingStatus::Completed, $actor, afterStartOnly: true);
    }

    /**
     * @throws SessionNotStarted
     */
    public function markNoShow(int $bookingId, Actor $actor): void
    {
        $this->changeStatus($bookingId, BookingStatus::NoShow, $actor, afterStartOnly: true);
    }

    /**
     * Cron: marks held and awaiting-verification bookings whose hold has lapsed as expired.
     *
     * @return int number of bookings expired
     */
    public function expireStale(): int
    {
        return $this->db->transaction(function (): int {
            $now = $this->clock->now();
            $ids = $this->bookings->lockLapsedPending($now);
            foreach ($ids as $id) {
                $this->bookings->setStatus($id, BookingStatus::Expired, $now);
                $this->bookings->audit(Actor::system(), 'booking.expired', $id, [], $now);
            }

            return count($ids);
        });
    }

    private function changeStatus(int $bookingId, BookingStatus $to, Actor $actor, bool $afterStartOnly = false): void
    {
        $this->db->transaction(function () use ($bookingId, $to, $actor, $afterStartOnly): void {
            $booking = $this->lockBooking($bookingId);
            $now = $this->clock->now();
            StatusMachine::assertTransition($booking->status, $to, $booking->paymentMethod);
            if ($afterStartOnly && $now < $booking->startAt) {
                throw new SessionNotStarted();
            }

            $this->bookings->setStatus($bookingId, $to, $now);
            $this->bookings->audit($actor, 'booking.' . $to->value, $bookingId, [], $now);
        });
    }

    private function assertPaymentMethod(ServiceRecord $service, PaymentMethod $method): void
    {
        $allowed = $service->isFree()
            ? $method === PaymentMethod::Free
            : $method !== PaymentMethod::Free && in_array($method, $service->paymentMethods, true);

        if (!$allowed) {
            throw new PaymentMethodNotAllowed();
        }
    }

    /**
     * Runs SlotEngine for the slot's local day with fresh data and requires the exact start.
     */
    private function assertOffered(ProviderRecord $provider, ServiceRecord $service, Interval $slot, DateTimeImmutable $now): void
    {
        $localDate = $slot->start->setTimezone($provider->timezone)->format('Y-m-d');
        $dayStart = new DateTimeImmutable($localDate, $provider->timezone);
        // A day either side catches neighbours whose gap reaches across midnight.
        $context = new Interval($dayStart->modify('-1 day'), $dayStart->modify('+2 days'));
        $bookings = $this->bookings->blockingIntervals($provider->id, $context, $now);

        $maxPerDay = $provider->rules->maxPerDay;
        if ($maxPerDay !== null && $this->countOnLocalDate($bookings, $provider, $localDate) >= $maxPerDay) {
            throw new DailyLimitReached();
        }

        $offered = $this->slotEngine->slots(new SlotRequest(
            timezone: $provider->timezone->getName(),
            rules: $provider->rules,
            weeklyRules: $this->bookings->weeklyRules($provider->id),
            serviceId: $service->id,
            durationMinutes: $service->durationMinutes,
            fromDate: $localDate,
            toDate: $localDate,
            now: $now,
            blocked: $this->bookings->blockedPeriods($provider->id, $context),
            bookings: $bookings,
        ));

        foreach ($offered as $candidate) {
            if ($candidate->start == $slot->start) {
                return;
            }
        }

        throw new SlotUnavailable();
    }

    /**
     * @param list<Interval> $bookings
     */
    private function countOnLocalDate(array $bookings, ProviderRecord $provider, string $localDate): int
    {
        return count(array_filter(
            $bookings,
            static fn(Interval $b): bool => $b->start->setTimezone($provider->timezone)->format('Y-m-d') === $localDate,
        ));
    }

    /**
     * @return array{int, string} booking id and the ref it was stored with
     */
    private function insertWithUniqueRef(NewBooking $booking): array
    {
        for ($attempt = 1; $attempt <= self::MAX_REF_ATTEMPTS; $attempt++) {
            $candidate = $attempt === 1 ? $booking : $booking->withRef($this->refs->next());
            try {
                return [$this->bookings->insert($candidate), $candidate->ref];
            } catch (RefCollision) {
                continue;
            }
        }

        throw new RuntimeException('Could not generate a unique booking reference.');
    }

    private function lockBooking(int $bookingId): BookingRecord
    {
        return $this->bookings->lockBooking($bookingId) ?? throw new BookingNotFound();
    }

    private function assertHoldLive(BookingRecord $booking, DateTimeImmutable $now): void
    {
        if ($booking->holdLapsed($now)) {
            throw new HoldExpired();
        }
    }
}
