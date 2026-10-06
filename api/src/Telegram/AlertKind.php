<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

enum AlertKind: string
{
    case VerifyPayment = 'v';
    case ApprovalNeeded = 'a';
    /** A booking that confirmed itself (paid online, or free without approval): for information. */
    case NewBooking = 'n';
    /** An online payment arrived after the hold ended and needs refunding: for information. */
    case RefundNeeded = 'f';

    /** Whether the alert carries Confirm/Reject buttons (and is updated once the booking is settled). */
    public function needsAction(): bool
    {
        return $this === self::VerifyPayment || $this === self::ApprovalNeeded;
    }
}
