<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class HoldExpired extends DomainError
{
    public function __construct(string $message = 'This booking hold has expired. Please book again.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'hold_expired';
    }
}
