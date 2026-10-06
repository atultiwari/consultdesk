<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Notify;

use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Notify\Mail\BookingEmails;
use ConsultDesk\Notify\Mail\EmailTemplate;
use ConsultDesk\Tests\Support\BookingViews;
use PHPUnit\Framework\TestCase;

final class BookingEmailsTest extends TestCase
{
    private const STATUS_URL = 'https://book.example.test/b/CD-7F3K?t=secret-token';

    public function testPaymentDueEmailHasEverythingNeededToPayOnceAndInTime(): void
    {
        $email = (new BookingEmails())->render(EmailTemplate::CustomerPaymentDue, BookingViews::make(), self::STATUS_URL);

        self::assertSame('Complete your payment for CD-7F3K', $email->subject);
        foreach (['₹1,499', 'placeholder@upi', 'Demo Payee', 'Wed, 7 Oct 2026, 10:00 AM IST', '6:30 AM IST', self::STATUS_URL, 'once', 'one booking'] as $needle) {
            self::assertStringContainsString($needle, $email->text, $needle);
        }
        self::assertStringContainsString('Asha &lt;Placeholder&gt;', $email->html);
    }

    public function testStaffVerificationEmailShowsTheUtrAndCustomerDetails(): void
    {
        $view = BookingViews::make(['status' => BookingStatus::AwaitingVerification, 'utr' => '412345678901']);
        $email = (new BookingEmails())->render(EmailTemplate::StaffVerifyPayment, $view, self::STATUS_URL);

        self::assertSame('Verify UPI payment for CD-7F3K (₹1,499)', $email->subject);
        foreach (['412345678901', 'asha@example.test', '+910000000000', 'Thesis feedback', 'Research & thesis guidance'] as $needle) {
            self::assertStringContainsString($needle, $email->text, $needle);
        }
        self::assertStringNotContainsString(self::STATUS_URL, $email->text, 'staff never get the customer token');
    }

    public function testExpiredEmailDiffersWhenAUtrWasSubmitted(): void
    {
        $emails = new BookingEmails();
        $unpaid = $emails->render(EmailTemplate::CustomerExpired, BookingViews::make(['status' => BookingStatus::Expired]), self::STATUS_URL);
        $paid = $emails->render(EmailTemplate::CustomerExpired, BookingViews::make(['status' => BookingStatus::Expired, 'utr' => '412345678901']), self::STATUS_URL);

        self::assertStringContainsString('do not pay', strtolower($unpaid->text));
        self::assertStringContainsString('412345678901', $paid->text);
        self::assertStringContainsString('contact', strtolower($paid->text));
    }

    public function testEveryTemplateRendersWithASubjectAndBothBodies(): void
    {
        $emails = new BookingEmails();
        foreach (EmailTemplate::cases() as $template) {
            $view = BookingViews::make([
                'paymentMethod' => $template === EmailTemplate::CustomerRequestReceived || $template === EmailTemplate::StaffApprovalNeeded ? PaymentMethod::Free : PaymentMethod::Upi,
                'meetUrl' => 'https://meet.example.test/abc',
            ]);
            $email = $emails->render($template, $view, self::STATUS_URL);

            self::assertNotSame('', $email->subject, $template->value);
            self::assertStringContainsString('CD-7F3K', $email->text, $template->value);
            self::assertStringContainsString('<html', $email->html, $template->value);
            self::assertSame($template->isForCustomer(), str_contains($email->text, self::STATUS_URL), $template->value);
        }
    }

    public function testTimesUseTheCustomersTimezoneWhenKnown(): void
    {
        $email = (new BookingEmails())->render(
            EmailTemplate::CustomerConfirmed,
            BookingViews::make(['status' => BookingStatus::Confirmed, 'customerTimezone' => 'Europe/London']),
            self::STATUS_URL,
        );

        self::assertStringContainsString('Wed, 7 Oct 2026, 5:30 AM BST', $email->text);
    }
}
