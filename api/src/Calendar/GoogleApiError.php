<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use RuntimeException;

/**
 * A Google API call failed. Messages never include tokens or the client secret.
 */
final class GoogleApiError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly bool $permanent = false,
        /** The refresh token was revoked or expired: the provider must reconnect. */
        public readonly bool $invalidGrant = false,
    ) {
        parent::__construct($message);
    }
}
