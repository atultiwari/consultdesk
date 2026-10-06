<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Domain\DomainError;

final class AuthFailed extends DomainError
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('That email and password don’t match.', 'invalid_credentials');
    }

    public static function tooManyAttempts(): self
    {
        return new self('Too many failed attempts. Please wait 15 minutes and try again.', 'too_many_attempts');
    }

    public static function invalidResetLink(): self
    {
        return new self('This reset link has expired or was already used. Ask for a new one.', 'invalid_reset_link');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
