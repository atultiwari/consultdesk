<?php

declare(strict_types=1);

namespace ConsultDesk\Domain;

use RuntimeException;

/**
 * Base for expected business-rule failures. The HTTP layer maps errorCode() to a response;
 * messages are safe to show to the user.
 */
abstract class DomainError extends RuntimeException
{
    abstract public function errorCode(): string;
}
