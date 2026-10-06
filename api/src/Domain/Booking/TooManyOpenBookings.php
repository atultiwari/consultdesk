<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class TooManyOpenBookings extends DomainError
{
    public function __construct(string $message = 'You already have several bookings waiting for payment or approval. Please complete or cancel those first.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'too_many_open_bookings';
    }
}
