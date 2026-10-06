<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\PayloadReader;
use RuntimeException;

final class GoogleDeleteEventHandler implements JobHandler
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
        if ($booking->gcalEventId === null) {
            return;
        }

        try {
            $this->calendar->deleteEvent($booking->providerId, $booking->gcalEventId);
        } catch (CalendarDisconnected) {
            // Cannot reach their calendar any more; forget the link so the event is not retried forever.
        }
        $this->links->detach($bookingId);
    }
}
