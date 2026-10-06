<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Availability\Interval;

/**
 * Stand-in used where only GoogleCalendar's pure helpers are needed; any call to Google fails.
 */
final class NullGoogleApi implements GoogleApi
{
    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        throw self::off();
    }

    public function exchangeCode(string $code, string $codeVerifier): GoogleTokens
    {
        throw self::off();
    }

    public function refresh(string $refreshToken): GoogleTokens
    {
        throw self::off();
    }

    public function revoke(string $token): void
    {
        throw self::off();
    }

    public function accountEmail(string $accessToken): ?string
    {
        throw self::off();
    }

    public function calendars(string $accessToken): array
    {
        throw self::off();
    }

    public function freeBusy(string $accessToken, array $calendarIds, Interval $range): array
    {
        throw self::off();
    }

    public function insertEvent(string $accessToken, string $calendarId, array $event): GoogleEvent
    {
        throw self::off();
    }

    public function getEvent(string $accessToken, string $calendarId, string $eventId): GoogleEvent
    {
        throw self::off();
    }

    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        throw self::off();
    }

    private static function off(): GoogleApiError
    {
        return new GoogleApiError('Google Calendar is not configured.');
    }
}
