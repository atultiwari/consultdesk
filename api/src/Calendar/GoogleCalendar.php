<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Notify\Outbox;

/**
 * A provider's Google Calendar: free/busy, and the events for confirmed bookings.
 * Access tokens are refreshed when needed; a revoked grant marks the connection broken and
 * queues one notice to staff.
 */
final class GoogleCalendar
{
    public const DISCONNECTED_JOB = 'google.disconnected';
    /** Refresh this long before Google's stated expiry. */
    private const EXPIRY_SKEW_SECONDS = 60;

    public function __construct(
        private readonly GoogleApi $api,
        private readonly GoogleConnections $connections,
        private readonly Outbox $outbox,
        private readonly Clock $clock,
        #[\SensitiveParameter]
        private readonly string $idKey,
    ) {}

    public function isConnected(int $providerId): bool
    {
        return $this->connections->find($providerId)?->active === true;
    }

    /**
     * @return list<Interval>
     *
     * @throws CalendarDisconnected|GoogleApiError
     */
    public function busy(int $providerId, Interval $range): array
    {
        $connection = $this->activeConnection($providerId);
        if ($connection->busyCalendarIds === []) {
            return [];
        }

        return $this->api->freeBusy($this->accessToken($connection), $connection->busyCalendarIds, $range);
    }

    /**
     * Creates the event for a confirmed booking, inviting the customer and adding a Meet link.
     * Safe to retry: the event id is derived from the booking, so a second attempt finds the first
     * (and restores it if someone deleted it in Google).
     *
     * @return array{GoogleEvent, string} the event and the calendar it is on
     *
     * @throws CalendarDisconnected|GoogleApiError
     */
    public function createEvent(BookingView $booking): array
    {
        $connection = $this->activeConnection($booking->providerId);
        $calendarId = $connection->targetCalendarId ?? 'primary';
        $token = $this->accessToken($connection);
        $eventId = $this->eventIdFor($booking->id);
        $body = EventBody::for($booking, $eventId);

        try {
            return [$this->api->insertEvent($token, $calendarId, $body), $calendarId];
        } catch (GoogleApiError $e) {
            if ($e->status !== 409) {
                throw $e;
            }
        }

        $existing = $this->api->getEvent($token, $calendarId, $eventId);
        if ($existing->cancelled) {
            $existing = $this->api->updateEvent($token, $calendarId, $eventId, $body + ['status' => 'confirmed']);
        }

        return [$existing, $calendarId];
    }

    /**
     * @throws CalendarDisconnected|GoogleApiError
     */
    public function fetchEvent(int $providerId, string $calendarId, string $eventId): GoogleEvent
    {
        return $this->api->getEvent($this->accessToken($this->activeConnection($providerId)), $calendarId, $eventId);
    }

    /**
     * @throws CalendarDisconnected|GoogleApiError
     */
    public function deleteEvent(int $providerId, string $calendarId, string $eventId): void
    {
        $this->api->deleteEvent($this->accessToken($this->activeConnection($providerId)), $calendarId, $eventId);
    }

    /**
     * @return list<GoogleCalendarEntry>
     *
     * @throws CalendarDisconnected|GoogleApiError
     */
    public function calendars(int $providerId): array
    {
        return $this->api->calendars($this->accessToken($this->activeConnection($providerId)));
    }

    /**
     * Revokes access at Google (best effort) and forgets the tokens.
     */
    public function disconnect(int $providerId): void
    {
        $connection = $this->connections->find($providerId);
        if ($connection === null) {
            return;
        }
        try {
            $this->api->revoke($connection->refreshToken);
        } catch (GoogleApiError) {
            // Already revoked or unreachable: the tokens are deleted either way.
        }
        $this->connections->delete($providerId);
    }

    /**
     * Google event ids allow a–v and 0–9; hex qualifies. Keyed so ids are unique per installation.
     */
    public function eventIdFor(int $bookingId): string
    {
        return 'cd' . substr(hash_hmac('sha256', "booking:{$bookingId}", $this->idKey), 0, 30);
    }

    private function activeConnection(int $providerId): GoogleConnection
    {
        $connection = $this->connections->find($providerId);
        if ($connection === null || !$connection->active) {
            throw new CalendarDisconnected("Provider {$providerId} has no active Google connection.");
        }

        return $connection;
    }

    private function accessToken(GoogleConnection $connection): string
    {
        $fresh = $connection->accessExpiresAt !== null
            && $connection->accessExpiresAt->getTimestamp() - self::EXPIRY_SKEW_SECONDS > $this->clock->now()->getTimestamp();
        if ($connection->accessToken !== null && $fresh) {
            return $connection->accessToken;
        }

        try {
            $tokens = $this->api->refresh($connection->refreshToken);
        } catch (GoogleApiError $e) {
            if ($e->invalidGrant) {
                if ($this->connections->markBroken($connection, $e->getMessage())) {
                    $this->outbox->enqueue(self::DISCONNECTED_JOB, ['provider_id' => $connection->providerId]);
                }
                throw new CalendarDisconnected('Google access was revoked; the provider needs to reconnect.');
            }
            throw $e;
        }
        $this->connections->saveAccessToken($connection, $tokens);

        return $tokens->accessToken;
    }
}
