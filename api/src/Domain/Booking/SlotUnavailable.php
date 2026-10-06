<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class SlotUnavailable extends DomainError
{
    public function __construct(string $message = 'That time is no longer available. Please pick another slot.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'slot_unavailable';
    }
}
