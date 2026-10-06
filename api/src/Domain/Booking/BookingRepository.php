<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Domain\Availability\WeeklyRule;
use DateTimeImmutable;

/**
 * Persistence for BookingService. Methods named lock* take row locks and must run inside a transaction.
 *
 * A booking "blocks" its slot when it is confirmed, or held / awaiting verification with a hold that
 * has not yet lapsed at $now.
 */
interface BookingRepository
{
    /**
     * Locks the provider row, serialising every booking write for that provider.
     */
    public function lockActiveProvider(int $providerId): ?ProviderRecord;

    /**
     * Same as lockActiveProvider() without the lock, for read-only use such as listing slots.
     */
    public function findActiveProvider(int $providerId): ?ProviderRecord;

    public function findActiveService(int $serviceId): ?ServiceRecord;

    /**
     * @return list<Interval> the provider's blocking bookings that overlap $range
     */
    public function blockingIntervals(int $providerId, Interval $range, DateTimeImmutable $now): array;

    /**
     * @return list<WeeklyRule> all of the provider's weekly rules, general and service-specific
     */
    public function weeklyRules(int $providerId): array;

    /**
     * @return list<Interval> provider-specific and organisation-wide blocked periods overlapping $range
     */
    public function blockedPeriods(int $providerId, Interval $range): array;

    /**
     * @throws RefCollision when the ref is already taken
     */
    public function insert(NewBooking $booking): int;

    public function lockBooking(int $bookingId): ?BookingRecord;

    /**
     * @throws DuplicateUtr
     */
    public function markAwaitingVerification(int $bookingId, Utr $utr, DateTimeImmutable $holdExpiresAt, DateTimeImmutable $now): void;

    public function markConfirmed(int $bookingId, ?int $confirmedByUserId, DateTimeImmutable $now): void;

    public function setStatus(int $bookingId, BookingStatus $status, DateTimeImmutable $now): void;

    /**
     * @return list<int> ids of held / awaiting-verification bookings whose hold has lapsed, locked
     */
    public function lockLapsedPending(DateTimeImmutable $now): array;

    /**
     * @param array<string, mixed> $data
     */
    public function audit(Actor $actor, string $action, int $bookingId, array $data, DateTimeImmutable $now): void;
}
