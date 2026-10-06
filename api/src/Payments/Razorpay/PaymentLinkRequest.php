<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

use DateTimeImmutable;

/**
 * What one booking's payment link asks for.
 */
final class PaymentLinkRequest
{
    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
        /** the booking reference, e.g. CD-7F3K; Razorpay echoes it back */
        public readonly string $reference,
        public readonly string $description,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly DateTimeImmutable $expireBy,
        public readonly string $callbackUrl,
    ) {}
}
