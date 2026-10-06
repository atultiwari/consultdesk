<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Notify;

use ConsultDesk\Notify\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testFormatsIndianRupeesWithoutNeedlessDecimals(): void
    {
        self::assertSame('₹1,499', Money::format(149900, 'INR'));
        self::assertSame('₹1,49,999.50', Money::format(14999950, 'INR'));
        self::assertSame('Free', Money::format(0, 'INR'));
    }

    public function testDecimalAmountForPaymentLinks(): void
    {
        self::assertSame('1499.00', Money::decimal(149900));
        self::assertSame('0.05', Money::decimal(5));
    }
}
