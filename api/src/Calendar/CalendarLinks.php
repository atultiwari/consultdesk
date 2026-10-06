<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Infra\Clock;
use PDO;

/**
 * The calendar event and Meet link stored on a booking.
 */
final class CalendarLinks
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function attach(int $bookingId, string $eventId, string $calendarId, ?string $meetUrl): void
    {
        $this->pdo->prepare(
            'UPDATE bookings SET gcal_event_id = :event, gcal_calendar_id = :calendar, meet_url = COALESCE(:meet, meet_url), updated_at = :now WHERE id = :id',
        )->execute([
            'event' => $eventId,
            'calendar' => $calendarId,
            'meet' => $meetUrl,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'id' => $bookingId,
        ]);
    }

    public function setMeetUrl(int $bookingId, string $meetUrl): void
    {
        $this->pdo->prepare('UPDATE bookings SET meet_url = :meet, updated_at = :now WHERE id = :id')
            ->execute(['meet' => $meetUrl, 'now' => $this->clock->now()->format('Y-m-d H:i:s'), 'id' => $bookingId]);
    }

    public function detach(int $bookingId): void
    {
        $this->pdo->prepare('UPDATE bookings SET gcal_event_id = NULL, gcal_calendar_id = NULL, meet_url = NULL, updated_at = :now WHERE id = :id')
            ->execute(['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'id' => $bookingId]);
    }
}
