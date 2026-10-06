<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class InvalidUtr extends DomainError
{
    public function __construct(string $message = 'A UTR is the 12-digit reference shown in your UPI app.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'invalid_utr';
    }
}
