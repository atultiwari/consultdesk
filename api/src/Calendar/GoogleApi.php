<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Availability\Interval;

/**
 * The Google OAuth and Calendar calls ConsultDesk makes, over plain REST (no 100 MB SDK).
 * Every method throws GoogleApiError on failure.
 */
interface GoogleApi
{
    /**
     * @param string $loginHint the Google account expected to sign in
     */
    public function authorizationUrl(string $state, string $codeChallenge, string $loginHint): string;

    public function exchangeCode(string $code, string $codeVerifier): GoogleTokens;

    public function refresh(string $refreshToken): GoogleTokens;

    public function revoke(string $token): void;

    public function accountEmail(string $accessToken): ?string;

    /**
     * @return list<GoogleCalendarEntry>
     */
    public function calendars(string $accessToken): array;

    /**
     * @param list<string> $calendarIds
     *
     * @return list<Interval>
     */
    public function freeBusy(string $accessToken, array $calendarIds, Interval $range): array;

    /**
     * Creates an event, inviting attendees and creating any requested conference (Meet).
     *
     * @param array<string, mixed> $event
     */
    public function insertEvent(string $accessToken, string $calendarId, array $event): GoogleEvent;

    public function getEvent(string $accessToken, string $calendarId, string $eventId): GoogleEvent;

    /**
     * Replaces an event, e.g. to restore one that was deleted in Google.
     *
     * @param array<string, mixed> $event
     */
    public function updateEvent(string $accessToken, string $calendarId, string $eventId, array $event): GoogleEvent;

    /**
     * Deletes an event and notifies attendees. An event that is already gone is not an error.
     */
    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void;
}
