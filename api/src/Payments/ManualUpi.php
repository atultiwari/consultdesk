<?php

declare(strict_types=1);

namespace ConsultDesk\Payments;

use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Notify\Money;
use LogicException;

/**
 * Manual UPI to the provider's own VPA (docs/PLAN.md §6.3): a upi:// deep link the customer's app
 * opens (or a QR of it on desktop), plus an optional WhatsApp link for sending the screenshot.
 */
final class ManualUpi
{
    public static function instructions(BookingView $booking): UpiInstructions
    {
        if ($booking->upiVpa === null || $booking->upiVpa === '') {
            throw new LogicException(sprintf('Provider %d has no UPI ID configured.', $booking->providerId));
        }

        $payee = $booking->upiPayeeName ?? $booking->providerName;
        $amount = Money::decimal($booking->amountMinor);
        $uri = 'upi://pay?' . http_build_query([
            'pa' => $booking->upiVpa,
            'pn' => $payee,
            'am' => $amount,
            'cu' => $booking->currency,
            'tn' => $booking->ref,
        ], '', '&', PHP_QUERY_RFC3986);

        return new UpiInstructions(
            $booking->upiVpa,
            $payee,
            $amount,
            Money::format($booking->amountMinor, $booking->currency),
            $uri,
            self::whatsappUrl($booking),
        );
    }

    private static function whatsappUrl(BookingView $booking): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $booking->providerWhatsapp) ?? '';
        if ($digits === '') {
            return null;
        }

        $when = $booking->slot->start->setTimezone($booking->displayTimezone())->format('D j M, g:i A T');
        $text = sprintf(
            'Hi, here is my payment screenshot for booking %s: %s for %s on %s.',
            $booking->ref,
            Money::format($booking->amountMinor, $booking->currency),
            $booking->serviceTitle,
            $when,
        );

        return sprintf('https://wa.me/%s?text=%s', $digits, rawurlencode($text));
    }
}
