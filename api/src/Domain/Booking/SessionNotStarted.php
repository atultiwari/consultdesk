<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\DomainError;

final class SessionNotStarted extends DomainError
{
    public function __construct(string $message = 'This session has not started yet.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'session_not_started';
    }
}
