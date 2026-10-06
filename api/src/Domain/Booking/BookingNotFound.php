<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class BookingNotFound extends DomainError
{
    public function __construct(string $message = 'Booking not found.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'booking_not_found';
    }
}
