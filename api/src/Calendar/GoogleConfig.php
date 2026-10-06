<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use InvalidArgumentException;

/**
 * The installation's Google Cloud OAuth client (one per site; each provider connects their own account).
 */
final class GoogleConfig
{
    public function __construct(
        public readonly string $clientId,
        #[\SensitiveParameter]
        public readonly string $clientSecret,
        public readonly string $redirectUri,
    ) {
        if (!str_ends_with($clientId, '.apps.googleusercontent.com')) {
            throw new InvalidArgumentException('GOOGLE_CLIENT_ID should end with .apps.googleusercontent.com.');
        }
        if (strlen($clientSecret) < 10) {
            throw new InvalidArgumentException('GOOGLE_CLIENT_SECRET is missing or too short.');
        }
    }

    /**
     * Null when Google Calendar is not configured.
     *
     * @param array<string, string> $values
     */
    public static function fromValues(array $values, string $appUrl): ?self
    {
        $clientId = trim($values['GOOGLE_CLIENT_ID'] ?? '');
        if ($clientId === '') {
            return null;
        }

        return new self($clientId, trim($values['GOOGLE_CLIENT_SECRET'] ?? ''), $appUrl . '/api/google/callback');
    }
}
