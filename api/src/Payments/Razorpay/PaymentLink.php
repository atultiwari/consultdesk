<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

final class PaymentLink
{
    public function __construct(
        public readonly string $id,
        public readonly string $shortUrl,
    ) {}
}
