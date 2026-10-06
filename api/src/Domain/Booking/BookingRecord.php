<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use DateTimeImmutable;

final class BookingRecord
{
    public function __construct(
        public readonly int $id,
        public readonly BookingStatus $status,
        public readonly PaymentMethod $paymentMethod,
        public readonly ?DateTimeImmutable $holdExpiresAt,
        public readonly DateTimeImmutable $startAt,
    ) {}

    /**
     * A pending booking whose hold has run out no longer owns its slot, even before cron marks it expired.
     */
    public function holdLapsed(DateTimeImmutable $now): bool
    {
        return in_array($this->status, [BookingStatus::Held, BookingStatus::AwaitingVerification], true)
            && $this->holdExpiresAt !== null
            && $this->holdExpiresAt <= $now;
    }
}
