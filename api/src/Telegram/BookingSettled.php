<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Domain\DomainError;

/**
 * A button was pressed for a booking that is no longer waiting for a decision.
 */
final class BookingSettled extends DomainError
{
    public function __construct()
    {
        parent::__construct('This booking has already been settled.');
    }

    public function errorCode(): string
    {
        return 'booking_settled';
    }
}
