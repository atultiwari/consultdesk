<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Availability\BusyTimeSource;
use ConsultDesk\Domain\Availability\BusyWindow;
use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Domain\Availability\NoBusyTime;
use ConsultDesk\Domain\Availability\SlotEngine;
use ConsultDesk\Domain\Availability\SlotRequest;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Crypto;
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
    /** Open (unpaid or unapproved) bookings one email address may have at once. */
    public const MAX_OPEN_PER_EMAIL = 3;

    public function __construct(
        private readonly Db $db,
        private readonly BookingRepository $bookings,
        private readonly Clock $clock,
        private readonly RefGenerator $refs,
        private readonly BookingEvents $events,
        private readonly Crypto $crypto,
        private readonly BusyTimeSource $busy = new NoBusyTime(),
        private readonly SlotEngine $slotEngine = new SlotEngine(),
    ) {}

    /**
     * Reserves a slot while the customer pays or awaits approval.
     *
     * Under the provider lock, the requested start must be a slot SlotEngine offers right now:
     * inside the weekly hours, on the slot grid, outside blocked periods, within notice and horizon,
     * clear of other bookings by the gap and under the daily cap.
     *
     * With $confirmImmediately (free services that need no approval) the booking is confirmed in
     * the same transaction, so a failure can never leave a stray hold behind.
     *
     * @throws ServiceNotBookable|PaymentMethodNotAllowed|SlotUnavailable|DailyLimitReached|TooManyOpenBookings
     */
    public function hold(HoldRequest $request, bool $confirmImmediately = false): HeldBooking
    {
        if ($confirmImmediately && $request->paymentMethod !== PaymentMethod::Free) {
            throw new PaymentMethodNotAllowed();
        }

        // External busy time is fetched before taking the provider lock, so a slow calendar API
        // can never hold up other bookings for this provider.
        $busy = $this->externalBusy($request);

        return $this->db->transaction(function () use ($request, $confirmImmediately, $busy): HeldBooking {
            $provider = $this->bookings->lockActiveProvider($request->providerId) ?? throw new ServiceNotBookable();
            $now = $this->clock->now();
            $service = $this->bookings->findActiveService($request->serviceId);
            if ($service === null || $service->providerId !== $provider->id) {
                throw new ServiceNotBookable();
            }
            $this->assertPaymentMethod($service, $request->paymentMethod);
            if ($this->bookings->countOpenForEmail($request->customer->email, $now) >= self::MAX_OPEN_PER_EMAIL) {
                throw new TooManyOpenBookings();
            }

            $slot = new Interval($request->start, $request->start->modify(sprintf('+%d minutes', $service->durationMinutes)));
            $this->assertOffered($provider, $service, $slot, $now, $busy);

            $token = $this->refs->token();
            $holdExpiresAt = min($now->modify(sprintf('+%d minutes', HoldPolicy::holdMinutes($request->paymentMethod))), $slot->start);
            [$id, $ref] = $this->insertWithUniqueRef(new NewBooking(
                $this->refs->next(),
                RandomRefGenerator::hashToken($token),
                $this->crypto->encrypt($token),
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
            if ($confirmImmediately) {
                $this->bookings->markConfirmed($id, null, $now);
                $this->bookings->audit(Actor::system(), 'booking.confirmed', $id, [], $now);
                $this->events->record(BookingEvent::Confirmed, $id);
            } else {
                $this->events->record(BookingEvent::Held, $id);
            }

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
            $this->events->record(BookingEvent::UtrSubmitted, $bookingId);
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

            $this->bookings->markConfirmed($bookingId, in_array($actor->type, [ActorType::User, ActorType::Telegram], true) ? $actor->id : null, $now);
            $this->bookings->audit($actor, 'booking.confirmed', $bookingId, [], $now);
            $this->events->record(BookingEvent::Confirmed, $bookingId);
        });
    }

    /**
     * An online payment for this booking's payment link arrived (webhook or signed return).
     *
     * A live hold is confirmed. Repeats are harmless. If the hold had already ended (or the booking
     * was cancelled), the slot may belong to someone else, so the booking is not confirmed: the
     * payment is recorded and staff are told to refund it.
     *
     * @return bool whether this call confirmed the booking
     *
     * @throws BookingNotFound
     */
    public function confirmPaid(int $bookingId, string $paymentId, Actor $actor): bool
    {
        return $this->db->transaction(function () use ($bookingId, $paymentId, $actor): bool {
            $booking = $this->lockBooking($bookingId);
            $now = $this->clock->now();
            if ($booking->paymentMethod !== PaymentMethod::RazorpayLink || $this->bookings->paymentRecorded($bookingId, $paymentId)) {
                return false;
            }

            $this->bookings->recordPayment($bookingId, $paymentId, $now);
            $live = $booking->status === BookingStatus::Held && ($booking->holdExpiresAt === null || $booking->holdExpiresAt > $now);
            if (!$live) {
                if (in_array($booking->status, [BookingStatus::Held, BookingStatus::AwaitingVerification], true)) {
                    // The hold ran out but cron hasn't marked it yet: do it now, quietly; the
                    // paid-late emails below explain what happened.
                    $this->bookings->setStatus($bookingId, BookingStatus::Expired, $now);
                    $this->bookings->audit(Actor::system(), 'booking.expired', $bookingId, [], $now);
                }
                $this->bookings->audit($actor, 'booking.paid_late', $bookingId, ['payment_id' => $paymentId, 'status' => $booking->status->value], $now);
                $this->events->record(BookingEvent::PaidLate, $bookingId);

                return false;
            }

            $this->bookings->markConfirmed($bookingId, null, $now);
            $this->bookings->audit($actor, 'booking.confirmed', $bookingId, ['payment_id' => $paymentId], $now);
            $this->events->record(BookingEvent::Confirmed, $bookingId);

            return true;
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
                $this->events->record(BookingEvent::Expired, $id);
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
            $event = BookingEvent::tryFrom('booking.' . $to->value);
            if ($event !== null) {
                $this->events->record($event, $bookingId);
            }
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
     * @return list<Interval>
     */
    private function externalBusy(HoldRequest $request): array
    {
        $provider = $this->bookings->findActiveProvider($request->providerId);
        if ($provider === null) {
            return []; // the transaction reports the provider as not bookable
        }
        $window = BusyWindow::clip(self::context($request->start, $provider), $this->clock->now(), $provider->rules);

        return $window === null ? [] : $this->busy->busy($provider->id, $window);
    }

    /**
     * The slot's local day widened by a day either side, to catch neighbours whose gap reaches across midnight.
     */
    private static function context(DateTimeImmutable $start, ProviderRecord $provider): Interval
    {
        $dayStart = new DateTimeImmutable($start->setTimezone($provider->timezone)->format('Y-m-d'), $provider->timezone);

        return new Interval($dayStart->modify('-1 day'), $dayStart->modify('+2 days'));
    }

    /**
     * Runs SlotEngine for the slot's local day with fresh data and requires the exact start.
     *
     * @param list<Interval> $busy external busy time, fetched before the lock
     */
    private function assertOffered(ProviderRecord $provider, ServiceRecord $service, Interval $slot, DateTimeImmutable $now, array $busy): void
    {
        $localDate = $slot->start->setTimezone($provider->timezone)->format('Y-m-d');
        $context = self::context($slot->start, $provider);
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
            busy: $busy,
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
