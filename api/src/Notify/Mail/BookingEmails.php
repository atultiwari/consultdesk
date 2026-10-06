<?php

declare(strict_types=1);

namespace ConsultDesk\Notify\Mail;

use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Notify\Money;
use DateTimeImmutable;

/**
 * Subject and body for every booking email. Customer emails link to the status page;
 * staff emails never contain the customer's status token.
 */
final class BookingEmails
{
    private const WHEN_FORMAT = 'D, j M Y, g:i A T';
    private const TIME_FORMAT = 'g:i A T';
    private const UTR_NOTE = 'Pay once, for this booking only. A UPI reference (UTR) can confirm only one booking, '
        . 'so if your hold runs out before you submit the UTR, please do not pay. Book a new slot instead.';

    public function render(EmailTemplate $template, BookingView $booking, string $statusUrl): RenderedEmail
    {
        // Customers see their own timezone; staff see the provider's.
        $booking = $template->isForCustomer() ? $booking : $booking->asSeenByStaff();

        return match ($template) {
            EmailTemplate::CustomerPaymentDue => $this->paymentDue($booking, $statusUrl),
            EmailTemplate::CustomerPayOnline => $this->customer(
                $booking,
                $statusUrl,
                "Complete your payment for {$booking->ref}",
                'Complete your payment',
                sprintf(
                    'Hi %s, your slot is held until %s. Pay %s online (card, UPI, netbanking or wallet) from your booking page to confirm it.',
                    $booking->customerName,
                    $this->time($booking->holdExpiresAt, $booking),
                    $this->amount($booking),
                ),
            ),
            EmailTemplate::CustomerPaidLate => $this->customer(
                $booking,
                $statusUrl,
                "Payment received after your hold ended: {$booking->ref}",
                'We received your payment, but the slot had been released',
                "Hi {$booking->customerName}, your payment of {$this->amount($booking)} arrived after the time we could hold your slot, so this booking was not confirmed. {$booking->providerName} will refund it in full, or contact you to find another time.",
            ),
            EmailTemplate::CustomerRequestReceived => $this->customer(
                $booking,
                $statusUrl,
                "Request received: {$booking->ref}",
                'We have your request',
                "Hi {$booking->customerName}, thanks for your request. {$booking->providerName} will review it and you will get an email as soon as it is approved.",
            ),
            EmailTemplate::CustomerPaymentReceived => $this->customer(
                $booking,
                $statusUrl,
                "Payment received, verifying: {$booking->ref}",
                'Thanks, we are checking your payment',
                "Hi {$booking->customerName}, we have your UPI reference {$booking->utr}. Your slot stays reserved while {$booking->providerName} verifies the payment, and you will get a confirmation email once it is done.",
            ),
            EmailTemplate::CustomerConfirmed => $this->confirmed($booking, $statusUrl),
            EmailTemplate::CustomerRejected => $this->customer(
                $booking,
                $statusUrl,
                "Booking not confirmed: {$booking->ref}",
                'Your booking could not be confirmed',
                "Hi {$booking->customerName}, {$booking->providerName} could not confirm this booking. If you have already paid, please reply to this email or contact {$booking->providerName} with your booking reference.",
            ),
            EmailTemplate::CustomerCancelled => $this->customer(
                $booking,
                $statusUrl,
                "Booking cancelled: {$booking->ref}",
                'Your booking has been cancelled',
                "Hi {$booking->customerName}, this booking has been cancelled and the slot has been released.",
            ),
            EmailTemplate::CustomerExpired => $this->expired($booking, $statusUrl),
            EmailTemplate::StaffApprovalNeeded => $this->staff(
                $booking,
                "Approve booking request {$booking->ref}",
                'New booking request to approve',
                "{$booking->customerName} has asked for a session. It is held for you until {$this->time($booking->holdExpiresAt, $booking)}; approve or reject it before then.",
            ),
            EmailTemplate::StaffVerifyPayment => $this->staff(
                $booking,
                sprintf('Verify UPI payment for %s (%s)', $booking->ref, $this->amount($booking)),
                'A UPI payment needs verifying',
                "{$booking->customerName} says they paid {$this->amount($booking)} with UTR {$booking->utr}. Check that it arrived in your UPI app, then confirm or reject the booking before {$this->time($booking->holdExpiresAt, $booking)}.",
            ),
            EmailTemplate::StaffRefundNeeded => $this->staff(
                $booking,
                "Refund needed: {$booking->ref}",
                'An online payment arrived too late',
                "{$booking->customerName} paid {$this->amount($booking)} online after the hold on {$this->when($booking)} ended, so the booking was not confirmed. Refund it from the Razorpay dashboard, or offer another slot.",
            ),
            EmailTemplate::StaffConfirmed => $this->staff(
                $booking,
                "Booking confirmed: {$booking->ref}",
                'Booking confirmed',
                "The session with {$booking->customerName} on {$this->when($booking)} is confirmed.",
            ),
        };
    }

    private function paymentDue(BookingView $booking, string $statusUrl): RenderedEmail
    {
        $body = (new EmailBody())
            ->heading('Complete your payment')
            ->paragraph(sprintf(
                'Hi %s, your slot is held until %s. Pay %s by UPI, then enter the 12-digit UTR (UPI reference) on your booking page.',
                $booking->customerName,
                $this->time($booking->holdExpiresAt, $booking),
                $this->amount($booking),
            ))
            ->details([
                ...$this->summary($booking),
                'UPI ID' => (string) $booking->upiVpa,
                'Payee' => (string) ($booking->upiPayeeName ?? $booking->providerName),
            ])
            ->button('Open your booking page to pay', $statusUrl)
            ->note(self::UTR_NOTE);

        return $this->email("Complete your payment for {$booking->ref}", $body);
    }

    private function confirmed(BookingView $booking, string $statusUrl): RenderedEmail
    {
        $body = (new EmailBody())
            ->heading('Your session is confirmed')
            ->paragraph("Hi {$booking->customerName}, see you on {$this->when($booking)}.")
            ->details($this->summary($booking));
        if ($booking->meetUrl !== null) {
            $body = $body->button('Join the video call', $booking->meetUrl);
        }
        $body = $body->button('View your booking', $statusUrl);

        return $this->email("Confirmed: {$booking->serviceTitle} on {$this->when($booking)} ({$booking->ref})", $body);
    }

    private function expired(BookingView $booking, string $statusUrl): RenderedEmail
    {
        $message = $booking->utr === null
            ? "Hi {$booking->customerName}, the hold on this slot ran out before payment was completed, so the slot has been released. Please do not pay for this booking; book a new slot if you still need a session."
            : "Hi {$booking->customerName}, your payment with UTR {$booking->utr} could not be verified in time, so the slot has been released. Please contact {$booking->providerName} with your booking reference so they can sort out your payment.";

        $body = (new EmailBody())
            ->heading('Your booking has expired')
            ->paragraph($message)
            ->details($this->summary($booking))
            ->button('View your booking', $statusUrl);

        return $this->email("Booking expired: {$booking->ref}", $body);
    }

    private function customer(BookingView $booking, string $statusUrl, string $subject, string $heading, string $message): RenderedEmail
    {
        $body = (new EmailBody())
            ->heading($heading)
            ->paragraph($message)
            ->details($this->summary($booking))
            ->button('View your booking', $statusUrl);

        return $this->email($subject, $body);
    }

    private function staff(BookingView $booking, string $subject, string $heading, string $message): RenderedEmail
    {
        $body = (new EmailBody())
            ->heading($heading)
            ->paragraph($message)
            ->details([
                ...$this->summary($booking),
                'Customer' => $booking->customerName,
                'Email' => $booking->customerEmail,
                'Phone' => $booking->customerPhone ?? '-',
                'Payment' => $booking->utr === null ? $booking->paymentMethod->value : "UPI, UTR {$booking->utr}",
            ])
            ->details($this->answers($booking));

        return $this->email($subject, $body);
    }

    /**
     * @return array<string, string>
     */
    private function summary(BookingView $booking): array
    {
        return [
            'Booking' => $booking->ref,
            'Session' => $booking->serviceTitle,
            'With' => $booking->providerName,
            'When' => $this->when($booking),
            'Duration' => sprintf('%d minutes', $booking->slot->minutes()),
            'Amount' => $this->amount($booking),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function answers(BookingView $booking): array
    {
        $rows = [];
        foreach ($booking->answers as $question => $answer) {
            $rows[ucfirst(str_replace('_', ' ', (string) $question))] = match (true) {
                is_bool($answer) => $answer ? 'Yes' : 'No',
                is_scalar($answer) => (string) $answer,
                default => (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };
        }

        return $rows;
    }

    private function email(string $subject, EmailBody $body): RenderedEmail
    {
        return new RenderedEmail($subject, $body->toText(), $body->toHtml());
    }

    private function when(BookingView $booking): string
    {
        return $booking->slot->start->setTimezone($booking->displayTimezone())->format(self::WHEN_FORMAT);
    }

    private function time(?DateTimeImmutable $instant, BookingView $booking): string
    {
        return $instant === null ? '-' : $instant->setTimezone($booking->displayTimezone())->format(self::TIME_FORMAT);
    }

    private function amount(BookingView $booking): string
    {
        return Money::format($booking->amountMinor, $booking->currency);
    }
}
