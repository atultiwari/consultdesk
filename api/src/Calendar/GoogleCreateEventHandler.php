<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\PayloadReader;
use RuntimeException;

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
        $booking = $this->views->findById($bookingId) ?? throw new RuntimeException("Booking {$bookingId} not found.");
        if ($booking->status !== BookingStatus::Confirmed || $booking->gcalEventId !== null) {
            return;
        }

        try {
            $event = $this->calendar->createEvent($booking);
        } catch (CalendarDisconnected) {
            return; // staff were told when access was revoked; the booking itself stands
        }
        $this->links->attach($bookingId, $event->id, $event->meetUrl);
    }
}
