<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

enum BookingStatus: string
{
    case Held = 'held';
    case AwaitingVerification = 'awaiting_verification';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Rescheduled = 'rescheduled';

    /**
     * Whether a booking in this status occupies its slot. Held and awaiting-verification
     * bookings only do so until hold_expires_at.
     */
    public function blocksSlot(): bool
    {
        return match ($this) {
            self::Held, self::AwaitingVerification, self::Confirmed => true,
            default => false,
        };
    }
}
