<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Support;

use ConsultDesk\Calendar\GoogleApi;
use ConsultDesk\Calendar\GoogleApiError;
use ConsultDesk\Calendar\GoogleCalendarEntry;
use ConsultDesk\Calendar\GoogleEvent;
use ConsultDesk\Calendar\GoogleTokens;
use ConsultDesk\Domain\Availability\Interval;

/**
 * In-memory Google: records calls and keeps events. Placeholder tokens only.
 */
final class FakeGoogleApi implements GoogleApi
{
    public ?string $refreshTokenToIssue = 'refresh-1';
    public string $scopeToIssue = 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy https://www.googleapis.com/auth/calendar.calendarlist.readonly';
    public bool $refreshRevoked = false;
    public bool $freeBusyDown = false;
    /** @var list<Interval> */
    public array $busy = [];
    /** @var array<string, array<string, mixed>> event id => event body */
    public array $events = [];
    /** @var list<array{string, string}> calendar id, event id */
    public array $deleted = [];
    /** @var list<string> */
    public array $calls = [];
    /** @var list<list<string>> calendar ids per freeBusy call */
    public array $freeBusyCalendars = [];
    /** @var list<string> */
    public array $revoked = [];
    private int $issued = 0;

    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return 'https://accounts.example.test/auth?state=' . rawurlencode($state) . '&code_challenge=' . rawurlencode($codeChallenge);
    }

    public function exchangeCode(string $code, string $codeVerifier): GoogleTokens
    {
        $this->calls[] = "exchange:{$code}:" . strlen($codeVerifier);

        return new GoogleTokens('access-' . ++$this->issued, $this->refreshTokenToIssue, 3600, $this->scopeToIssue);
    }

    public function refresh(string $refreshToken): GoogleTokens
    {
        $this->calls[] = "refresh:{$refreshToken}";
        if ($this->refreshRevoked) {
            throw new GoogleApiError('Google token failed: invalid_grant: Token has been expired or revoked.', 400, true, true);
        }

        return new GoogleTokens('access-' . ++$this->issued, null, 3600, $this->scopeToIssue);
    }

    public function revoke(string $token): void
    {
        $this->revoked[] = $token;
    }

    public function accountEmail(string $accessToken): string
    {
        return 'provider@example.test';
    }

    public function calendars(string $accessToken): array
    {
        return [
            new GoogleCalendarEntry('team@group.calendar.google.com', 'Team', false, false),
            new GoogleCalendarEntry('provider@example.test', 'Provider', true, true),
        ];
    }

    public function freeBusy(string $accessToken, array $calendarIds, Interval $range): array
    {
        $this->calls[] = "freeBusy:{$accessToken}";
        $this->freeBusyCalendars[] = $calendarIds;
        if ($this->freeBusyDown) {
            throw new GoogleApiError('Google API unreachable (freeBusy).');
        }

        return array_values(array_filter($this->busy, static fn(Interval $i): bool => $i->overlaps($range)));
    }

    public function insertEvent(string $accessToken, string $calendarId, array $event): GoogleEvent
    {
        $id = (string) ($event['id'] ?? '');
        $this->calls[] = "insert:{$calendarId}:{$id}";
        if (isset($this->events[$id])) {
            throw new GoogleApiError('Google events.insert failed: The requested identifier already exists.', 409, true);
        }
        $this->events[$id] = $event + ['calendar' => $calendarId];

        return new GoogleEvent($id, 'https://meet.example.test/' . substr($id, 0, 8));
    }

    public function getEvent(string $accessToken, string $calendarId, string $eventId): GoogleEvent
    {
        $this->calls[] = "get:{$calendarId}:{$eventId}";

        return new GoogleEvent($eventId, 'https://meet.example.test/' . substr($eventId, 0, 8));
    }

    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        $this->deleted[] = [$calendarId, $eventId];
        unset($this->events[$eventId]);
    }

    public function count(string $prefix): int
    {
        return count(array_filter($this->calls, static fn(string $c): bool => str_starts_with($c, $prefix)));
    }
}
