<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Notify\Money;
use ConsultDesk\Payments\ManualUpi;
use DateTimeImmutable;

/**
 * What the customer's status page sees. Only the token holder gets this, and even so it leaves
 * out the customer's contact details and intake answers.
 */
final class BookingPresenter
{
    public const ISO = 'Y-m-d\TH:i:s\Z';

    /**
     * @return array<string, mixed>
     */
    public static function present(BookingView $booking, DateTimeImmutable $now): array
    {
        $lapsed = $booking->holdLapsed($now);
        $status = $lapsed ? BookingStatus::Expired : $booking->status;
        $pending = in_array($status, [BookingStatus::Held, BookingStatus::AwaitingVerification], true);

        return [
            'ref' => $booking->ref,
            'status' => $status->value,
            'payment_method' => $booking->paymentMethod->value,
            'start' => $booking->slot->start->format(self::ISO),
            'end' => $booking->slot->end->format(self::ISO),
            'timezone' => $booking->displayTimezone()->getName(),
            'duration_minutes' => $booking->slot->minutes(),
            'service' => ['title' => $booking->serviceTitle],
            'provider' => ['name' => $booking->providerName, 'slug' => $booking->providerSlug],
            'customer_name' => $booking->customerName,
            'amount_minor' => $booking->amountMinor,
            'currency' => $booking->currency,
            'amount_display' => Money::format($booking->amountMinor, $booking->currency),
            'hold_expires_at' => $pending ? $booking->holdExpiresAt?->format(self::ISO) : null,
            'utr' => $booking->utr,
            'meet_url' => $status === BookingStatus::Confirmed ? $booking->meetUrl : null,
            'payment' => $pending && $booking->paymentMethod === PaymentMethod::Upi ? self::upi($booking, $status) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function upi(BookingView $booking, BookingStatus $status): array
    {
        $available = $booking->upiVpa !== null && $booking->upiVpa !== '';

        return [
            'method' => 'upi',
            // If the provider removed their UPI ID after booking, a customer who already paid can still send the UTR.
            'available' => $available,
            ...($available ? ManualUpi::instructions($booking)->toArray() : []),
            'can_submit_utr' => $status === BookingStatus::Held,
            'utr_single_use' => true,
        ];
    }
}
