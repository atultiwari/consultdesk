<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Support;

use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\PaymentMethod;
use DateTimeImmutable;

/**
 * Builds BookingView values for unit tests. All personal and payment details are placeholders.
 */
final class BookingViews
{
    /**
     * @param array<string, mixed> $overrides constructor arguments by name
     */
    public static function make(array $overrides = []): BookingView
    {
        $args = array_merge([
            'id' => 41,
            'ref' => 'CD-7F3K',
            'status' => BookingStatus::Held,
            'paymentMethod' => PaymentMethod::Upi,
            'slot' => Interval::fromStrings('2026-10-07T04:30Z', '2026-10-07T05:30Z'),
            'customerName' => 'Asha <Placeholder>',
            'customerEmail' => 'asha@example.test',
            'customerPhone' => '+910000000000',
            'customerTimezone' => 'Asia/Kolkata',
            'answers' => ['goal' => 'Thesis feedback'],
            'amountMinor' => 149900,
            'currency' => 'INR',
            'utr' => null,
            'holdExpiresAt' => new DateTimeImmutable('2026-10-05T01:00Z'),
            'publicTokenEnc' => null,
            'providerId' => 3,
            'providerSlug' => 'demo',
            'providerName' => 'Dr. Demo Provider',
            'providerTimezone' => 'Asia/Kolkata',
            'providerWhatsapp' => '+91 00000 00000',
            'providerNotifyEmail' => 'provider@example.test',
            'upiVpa' => 'placeholder@upi',
            'upiPayeeName' => 'Demo Payee',
            'serviceId' => 9,
            'serviceTitle' => 'Research & thesis guidance',
            'requiresApproval' => false,
            'meetUrl' => null,
        ], $overrides);

        return new BookingView(...$args);
    }
}
