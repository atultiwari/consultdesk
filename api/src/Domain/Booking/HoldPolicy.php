<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

/**
 * How long a pending booking keeps its slot.
 */
final class HoldPolicy
{
    /** After a UTR is submitted, the provider has this long to verify it. */
    public const VERIFICATION_WINDOW_MINUTES = 24 * 60;

    public static function holdMinutes(PaymentMethod $method): int
    {
        return match ($method) {
            // Half an hour to pay and send the UTR; then staff have up to a day to verify it.
            PaymentMethod::Upi => 30,
            // Razorpay payment links must stay open at least 15 minutes.
            PaymentMethod::RazorpayLink => 30,
            // Free sessions that need approval wait up to a day for the provider.
            PaymentMethod::Free => 24 * 60,
        };
    }
}
