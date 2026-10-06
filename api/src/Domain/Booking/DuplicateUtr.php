<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class DuplicateUtr extends DomainError
{
    public function __construct(string $message = 'This UTR has already been used for another booking.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'duplicate_utr';
    }
}
