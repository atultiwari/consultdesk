<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class DuplicateUtr extends DomainError
{
    public function __construct(string $message = 'This UTR has already been used for a booking and cannot be used again. If you paid for a booking that expired, please contact the provider with that booking\'s reference.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'duplicate_utr';
    }
}
