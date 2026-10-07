<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Coupon;

final class AppliedCoupon
{
    public function __construct(
        public readonly Coupon $coupon,
        public readonly int $priceMinor,
        public readonly int $discountMinor,
    ) {}

    public function totalMinor(): int
    {
        return $this->priceMinor - $this->discountMinor;
    }
}
