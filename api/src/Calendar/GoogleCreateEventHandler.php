<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\PayloadReader;
use RuntimeException;

/**
 * Creates the calendar event for a confirmed booking, then makes sure it has its Meet link.
 * Google sometimes creates the Meet link a moment later; the job then retries (with the outbox's
 * backoff) until it can store the link.
 */
final class GoogleCreateEventHandler implements JobHandler
{
    public function __construct(
        private readonly BookingViewRepository $views,
        private readonly GoogleCalendar $calendar,
        private readonly CalendarLinks $links,
    ) {}

    public function handle(array $payload): void
    {
        $bookingId = PayloadReader::int($payload, 'booking_id');
        $booking = $this->load($bookingId);
        if ($booking->status !== BookingStatus::Confirmed) {
            return;
        }

        try {
            $meetUrl = $booking->gcalEventId === null ? $this->create($booking) : $this->refetchMeet($booking);
        } catch (CalendarDisconnected) {
            return; // staff were told when access was revoked; the booking itself stands
        }

        if ($meetUrl === false) {
            return; // cancelled meanwhile; the event was removed again
        }
        if ($meetUrl === null) {
            throw new RuntimeException('Google Meet link is not ready yet; will try again.');
        }
    }

    /**
     * @return string|null|false the Meet link, null if still pending, false if the booking was cancelled meanwhile
     */
    private function create(BookingView $booking): string|null|false
    {
        [$event, $calendarId] = $this->calendar->createEvent($booking);
        $this->links->attach($booking->id, $event->id, $calendarId, $event->meetUrl);

        // A cancellation that landed while Google was creating the event saw no event to delete.
        if ($this->load($booking->id)->status !== BookingStatus::Confirmed) {
            $this->calendar->deleteEvent($booking->providerId, $calendarId, $event->id);
            $this->links->detach($booking->id);

            return false;
        }

        return $event->meetUrl;
    }

    private function refetchMeet(BookingView $booking): ?string
    {
        if ($booking->meetUrl !== null || $booking->gcalEventId === null) {
            return $booking->meetUrl;
        }

        $event = $this->calendar->fetchEvent($booking->providerId, $booking->gcalCalendarId ?? 'primary', $booking->gcalEventId);
        if ($event->meetUrl !== null) {
            $this->links->setMeetUrl($booking->id, $event->meetUrl);
        }

        return $event->meetUrl;
    }

    private function load(int $bookingId): BookingView
    {
        return $this->views->findById($bookingId) ?? throw new RuntimeException("Booking {$bookingId} not found.");
    }
}
