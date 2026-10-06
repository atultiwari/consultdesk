<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use ConsultDesk\Domain\Booking\BookingEvent;
use ConsultDesk\Domain\Booking\BookingEvents;

final class OutboxBookingEvents implements BookingEvents
{
    public function __construct(private readonly Outbox $outbox) {}

    public function record(BookingEvent $event, int $bookingId): void
    {
        $this->outbox->enqueue($event->value, ['booking_id' => $bookingId], sprintf('%s:%d', $event->value, $bookingId));
    }
}
