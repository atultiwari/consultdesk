<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Db;
use DateTimeImmutable;
use RuntimeException;

/**
 * Creates and moves bookings through their lifecycle (docs/PLAN.md §5).
 *
 * Every write locks the provider or booking row first, so concurrent requests for overlapping
 * slots are serialised per provider and exactly one can win. Side effects (calendar, email,
 * Telegram) are not done here; later phases add them through the outbox.
 */
final class BookingService
{
    private const MAX_REF_ATTEMPTS = 5;

    public function __construct(
        private readonly Db $db,
        private readonly BookingRepository $bookings,
        private readonly Clock $clock,
        private readonly RefGenerator $refs,
    ) {}

    /**
     * Reserves a slot while the customer pays or awaits approval.
     *
     * Callers should first offer the slot via SlotEngine; this re-checks, under the provider lock,
     * everything that can change between page load and submit: notice, horizon, overlaps and the daily cap.
     *
     * @throws ServiceNotBookable|PaymentMethodNotAllowed|SlotUnavailable|DailyLimitReached
     */
    public function hold(HoldRequest $request): HeldBooking
    {
        return $this->db->transaction(function () use ($request): HeldBooking {
            $now = $this->clock->now();
            $provider = $this->bookings->lockActiveProvider($request->providerId) ?? throw new ServiceNotBookable();
            $service = $this->bookings->findActiveService($request->serviceId);
            if ($service === null || $service->providerId !== $provider->id) {
                throw new ServiceNotBookable();
            }
            $this->assertPaymentMethod($service, $request->paymentMethod);

            $slot = new Interval($request->start, $request->start->modify(sprintf('+%d minutes', $service->durationMinutes)));
            $this->assertBookable($provider, $slot, $now);

            $token = $this->refs->token();
            $holdExpiresAt = $now->modify(sprintf('+%d minutes', HoldPolicy::holdMinutes($request->paymentMethod)));
            $booking = new NewBooking(
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
            );
            [$id, $ref] = $this->insertWithUniqueRef($booking);

            $this->bookings->audit(Actor::customer(), 'booking.held', $id, [
                'ref' => $ref,
                'start_at' => $slot->start->format(DATE_ATOM),
                'payment_method' => $request->paymentMethod->value,
            ], $now);

            return new HeldBooking($id, $ref, $token, $holdExpiresAt);
        });
    }

    /**
     * Records the customer's UPI reference and gives the provider a day to verify it.
     *
     * @throws InvalidUtr|BookingNotFound|PaymentMethodNotAllowed|HoldExpired|InvalidTransition|DuplicateUtr
     */
    public function submitUtr(int $bookingId, string $utr): void
    {
        $validUtr = new Utr($utr);

        $this->db->transaction(function () use ($bookingId, $validUtr): void {
            $now = $this->clock->now();
            $booking = $this->lockBooking($bookingId);
            if ($booking->paymentMethod !== PaymentMethod::Upi) {
                throw new PaymentMethodNotAllowed();
            }
            $this->assertHoldLive($booking, $now);
            StatusMachine::assertTransition($booking->status, BookingStatus::AwaitingVerification, $booking->paymentMethod);

            $verifyBy = $now->modify(sprintf('+%d minutes', HoldPolicy::VERIFICATION_WINDOW_MINUTES));
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
            $now = $this->clock->now();
            $booking = $this->lockBooking($bookingId);
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

    public function complete(int $bookingId, Actor $actor): void
    {
        $this->changeStatus($bookingId, BookingStatus::Completed, $actor);
    }

    public function markNoShow(int $bookingId, Actor $actor): void
    {
        $this->changeStatus($bookingId, BookingStatus::NoShow, $actor);
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

    private function changeStatus(int $bookingId, BookingStatus $to, Actor $actor): void
    {
        $this->db->transaction(function () use ($bookingId, $to, $actor): void {
            $now = $this->clock->now();
            $booking = $this->lockBooking($bookingId);
            StatusMachine::assertTransition($booking->status, $to, $booking->paymentMethod);

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

    private function assertBookable(ProviderRecord $provider, Interval $slot, DateTimeImmutable $now): void
    {
        $rules = $provider->rules;
        $earliest = $now->modify(sprintf('+%d minutes', $rules->minNoticeMinutes));
        $latest = $now->modify(sprintf('+%d days', $rules->horizonDays));
        if ($slot->start < $earliest || $slot->start > $latest) {
            throw new SlotUnavailable();
        }

        $gap = $rules->gapMinutes();
        if ($this->bookings->countBlockingOverlapping($provider->id, $slot->pad($gap, $gap), $now) > 0) {
            throw new SlotUnavailable();
        }

        if ($rules->maxPerDay !== null) {
            $localDay = new DateTimeImmutable($slot->start->setTimezone($provider->timezone)->format('Y-m-d'), $provider->timezone);
            $day = new Interval($localDay, $localDay->modify('+1 day'));
            if ($this->bookings->countBlockingStarting($provider->id, $day, $now) >= $rules->maxPerDay) {
                throw new DailyLimitReached();
            }
        }
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
