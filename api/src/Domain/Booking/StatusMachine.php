<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Booking\BookingStatus as S;
use ConsultDesk\Domain\Booking\PaymentMethod as M;

/**
 * Allowed booking status changes (docs/PLAN.md §5):
 *  - UPI:            held → awaiting_verification → confirmed | rejected
 *  - Razorpay link:  held → confirmed
 *  - Free/approval:  held → confirmed | rejected
 *  - Any pending booking can expire or be cancelled; a confirmed one can be
 *    cancelled, completed, marked no-show or rescheduled. Everything else is terminal.
 */
final class StatusMachine
{
    public static function canTransition(S $from, S $to, M $method): bool
    {
        return match ($from) {
            S::Held => match ($to) {
                S::AwaitingVerification => $method === M::Upi,
                S::Confirmed => $method !== M::Upi,
                S::Rejected => $method === M::Free,
                S::Expired, S::Cancelled => true,
                default => false,
            },
            S::AwaitingVerification => in_array($to, [S::Confirmed, S::Rejected, S::Expired, S::Cancelled], true),
            S::Confirmed => in_array($to, [S::Cancelled, S::Completed, S::NoShow, S::Rescheduled], true),
            default => false,
        };
    }

    /**
     * @throws InvalidTransition
     */
    public static function assertTransition(S $from, S $to, M $method): void
    {
        if (!self::canTransition($from, $to, $method)) {
            throw InvalidTransition::between($from, $to);
        }
    }
}
