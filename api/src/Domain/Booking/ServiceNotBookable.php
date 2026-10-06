<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class ServiceNotBookable extends DomainError
{
    public function __construct(string $message = 'This service is not available for booking.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'service_not_bookable';
    }
}
