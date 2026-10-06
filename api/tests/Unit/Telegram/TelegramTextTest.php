<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Telegram;

use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Telegram\AlertKind;
use ConsultDesk\Telegram\TelegramText;
use ConsultDesk\Tests\Support\BookingViews;
use PHPUnit\Framework\TestCase;

final class TelegramTextTest extends TestCase
{
    public function testVerifyPaymentAlertShowsWhatTheProviderNeedsToCheck(): void
    {
        $view = BookingViews::make([
            'status' => BookingStatus::AwaitingVerification,
            'utr' => '412345678901',
            'customerTimezone' => 'Europe/London',
            'holdExpiresAt' => new \DateTimeImmutable('2026-10-06T00:30Z'),
        ]);

        $text = TelegramText::alert($view, AlertKind::VerifyPayment);

        foreach (['Verify UPI payment', 'CD-7F3K', '₹1,499', '<code>412345678901</code>', 'Asha &lt;Placeholder&gt;', 'asha@example.test', 'Research &amp; thesis guidance', 'Wed, 7 Oct 2026, 10:00 AM IST', '6:00 AM IST'] as $needle) {
            self::assertStringContainsString($needle, $text, $needle);
        }
        self::assertStringNotContainsString('<Placeholder>', $text, 'customer input is escaped');
        self::assertStringNotContainsString('BST', $text, 'staff see the provider\'s timezone');
    }

    public function testApostrophesUseEntitiesTelegramUnderstands(): void
    {
        $text = TelegramText::alert(BookingViews::make(['customerName' => "Siobhán O'Brien"]), AlertKind::VerifyPayment);

        self::assertStringContainsString('Siobhán O&#039;Brien', $text);
        self::assertStringNotContainsString('&apos;', $text);
    }

    public function testApprovalAlertAndRejectPrompt(): void
    {
        $view = BookingViews::make(['paymentMethod' => PaymentMethod::Free, 'amountMinor' => 0, 'requiresApproval' => true]);

        self::assertStringContainsString('approve', strtolower(TelegramText::alert($view, AlertKind::ApprovalNeeded)));
        self::assertStringContainsString('Reject CD-7F3K?', TelegramText::rejectPrompt($view, AlertKind::ApprovalNeeded));
    }

    public function testResolutionNamesTheOutcome(): void
    {
        $outcomes = [
            BookingStatus::Confirmed->value => '✅ Confirmed',
            BookingStatus::Rejected->value => '❌ Rejected',
            BookingStatus::Expired->value => '⌛ Expired',
            BookingStatus::Cancelled->value => '🚫 Cancelled',
        ];
        foreach ($outcomes as $status => $label) {
            $text = TelegramText::resolution(BookingViews::make(['status' => BookingStatus::from($status)]));
            self::assertStringStartsWith($label, $text, $status);
            self::assertStringContainsString('CD-7F3K', $text);
        }
    }
}
