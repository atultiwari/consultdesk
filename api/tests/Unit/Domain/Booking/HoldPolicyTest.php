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
        self::assertSame(30, HoldPolicy::holdMinutes(PaymentMethod::Upi), "half an hour to pay and send the UTR");
        self::assertSame(24 * 60, HoldPolicy::VERIFICATION_WINDOW_MINUTES, "then up to a day for staff to verify");
        self::assertSame(30, HoldPolicy::holdMinutes(PaymentMethod::RazorpayLink));
        self::assertSame(24 * 60, HoldPolicy::holdMinutes(PaymentMethod::Free));
        self::assertSame(24 * 60, HoldPolicy::VERIFICATION_WINDOW_MINUTES);
    }
}
