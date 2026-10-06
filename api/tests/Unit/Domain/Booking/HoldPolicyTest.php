<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Booking;

use ConsultDesk\Domain\Booking\HoldPolicy;
use ConsultDesk\Domain\Booking\PaymentMethod;
use PHPUnit\Framework\TestCase;

final class HoldPolicyTest extends TestCase
{
    public function testHoldLengthsFollowThePlan(): void
    {
        self::assertSame(60, HoldPolicy::holdMinutes(PaymentMethod::Upi));
        self::assertSame(20, HoldPolicy::holdMinutes(PaymentMethod::RazorpayLink));
        self::assertSame(24 * 60, HoldPolicy::holdMinutes(PaymentMethod::Free));
        self::assertSame(24 * 60, HoldPolicy::VERIFICATION_WINDOW_MINUTES);
    }
}
