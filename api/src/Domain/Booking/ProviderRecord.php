<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Availability\BookingRules;
use DateTimeZone;

final class ProviderRecord
{
    public function __construct(
        public readonly int $id,
        public readonly DateTimeZone $timezone,
        public readonly BookingRules $rules,
    ) {}
}
