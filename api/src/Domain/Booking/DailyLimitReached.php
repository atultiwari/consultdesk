<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class DailyLimitReached extends DomainError
{
    public function __construct(string $message = 'This day is fully booked. Please pick another day.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'daily_limit_reached';
    }
}
