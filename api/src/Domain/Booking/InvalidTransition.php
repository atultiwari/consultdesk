<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class InvalidTransition extends DomainError
{
    public static function between(BookingStatus $from, BookingStatus $to): self
    {
        return new self(sprintf('A booking cannot move from %s to %s.', $from->value, $to->value));
    }

    public function errorCode(): string
    {
        return 'invalid_transition';
    }
}
