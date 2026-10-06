<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

/**
 * Things that happen to a booking which other parts of the system (email, Telegram, calendar) react to.
 */
enum BookingEvent: string
{
    case Held = 'booking.held';
    case UtrSubmitted = 'booking.utr_submitted';
    case Confirmed = 'booking.confirmed';
    case Rejected = 'booking.rejected';
    case Cancelled = 'booking.cancelled';
    case Expired = 'booking.expired';
}
