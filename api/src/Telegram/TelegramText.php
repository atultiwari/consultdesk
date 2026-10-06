<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Notify\Money;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Message text for staff chats, in Telegram's HTML subset. Everything from customers is escaped,
 * and times are in the provider's timezone.
 */
final class TelegramText
{
    private const WHEN = 'D, j M Y, g:i A T';
    private const TIME = 'g:i A T';

    public static function alert(BookingView $booking, AlertKind $kind): string
    {
        $deadline = self::time($booking->holdExpiresAt, $booking);

        return match ($kind) {
            AlertKind::VerifyPayment => implode("\n", [
                '💰 <b>Verify UPI payment</b>',
                self::summary($booking),
                'UTR: <code>' . self::e((string) $booking->utr) . '</code>',
                '',
                sprintf('Check that %s arrived in your UPI app, then confirm before %s.', self::e(self::amount($booking)), self::e($deadline)),
            ]),
            AlertKind::ApprovalNeeded => implode("\n", [
                '📝 <b>Booking request to approve</b>',
                self::summary($booking),
                '',
                sprintf('Approve or reject before %s.', self::e($deadline)),
            ]),
        };
    }

    public static function rejectPrompt(BookingView $booking, AlertKind $kind): string
    {
        return self::alert($booking, $kind) . "\n\n" . sprintf('<b>Reject %s?</b> The customer will be emailed.', self::e($booking->ref));
    }

    public static function resolution(BookingView $booking): string
    {
        $label = match ($booking->status) {
            BookingStatus::Confirmed => '✅ Confirmed',
            BookingStatus::Rejected => '❌ Rejected',
            BookingStatus::Expired => '⌛ Expired',
            BookingStatus::Cancelled => '🚫 Cancelled',
            default => '⏳ ' . ucfirst(str_replace('_', ' ', $booking->status->value)),
        };

        return $label . "\n" . self::summary($booking);
    }

    private static function summary(BookingView $booking): string
    {
        return implode("\n", [
            sprintf('<b>%s</b> · %s', self::e($booking->ref), self::e(self::amount($booking))),
            sprintf('%s with %s', self::e($booking->serviceTitle), self::e($booking->providerName)),
            self::e($booking->slot->start->setTimezone(self::tz($booking))->format(self::WHEN)),
            sprintf('%s · %s · %s', self::e($booking->customerName), self::e($booking->customerEmail), self::e($booking->customerPhone ?? '-')),
        ]);
    }

    private static function amount(BookingView $booking): string
    {
        return Money::format($booking->amountMinor, $booking->currency);
    }

    private static function time(?DateTimeImmutable $instant, BookingView $booking): string
    {
        return $instant === null ? '-' : $instant->setTimezone(self::tz($booking))->format(self::TIME);
    }

    private static function tz(BookingView $booking): DateTimeZone
    {
        return new DateTimeZone($booking->providerTimezone);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
