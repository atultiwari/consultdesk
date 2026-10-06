<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

/**
 * Records booking events. Implementations must write inside the caller's open transaction,
 * so an event exists if and only if the status change it describes was committed.
 */
interface BookingEvents
{
    public function record(BookingEvent $event, int $bookingId): void;
}
