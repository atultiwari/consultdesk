<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Coupon;

use ConsultDesk\Domain\Coupon\Coupon;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CouponTest extends TestCase
{
    public function testPercentOffRoundsTheDiscountDownToWholeRupees(): void
    {
        self::assertSame(19900, self::coupon(['kind' => 'percent', 'value' => 20])->discountFor(99900), '20% of ₹999 is ₹199.80 → ₹199');
        self::assertSame(99900, self::coupon(['kind' => 'percent', 'value' => 100])->discountFor(99900), '100% is always the whole price');
    }

    public function testAmountOffNeverGoesBelowFree(): void
    {
        self::assertSame(20000, self::coupon(['kind' => 'amount', 'value' => 20000])->discountFor(99900));
        self::assertSame(49900, self::coupon(['kind' => 'amount', 'value' => 100000])->discountFor(49900));
    }

    public function testItAppliesToItsTeacherAndChosenSessionsOnly(): void
    {
        $siteWide = self::coupon([]);
        self::assertTrue($siteWide->appliesTo(1, 10));

        $teachers = self::coupon(['provider_id' => 1]);
        self::assertTrue($teachers->appliesTo(1, 10));
        self::assertFalse($teachers->appliesTo(2, 20));

        $someSessions = self::coupon(['provider_id' => 1, 'service_ids' => '[10, 11]']);
        self::assertTrue($someSessions->appliesTo(1, 11));
        self::assertFalse($someSessions->appliesTo(1, 12));
    }

    public function testItIsLiveOnlyWhenActiveAndInsideItsDates(): void
    {
        $now = new DateTimeImmutable('2026-10-05T00:00Z');
        self::assertTrue(self::coupon([])->isLive($now));
        self::assertFalse(self::coupon(['active' => 0])->isLive($now));
        self::assertFalse(self::coupon(['valid_from' => '2026-10-06 00:00:00'])->isLive($now));
        self::assertFalse(self::coupon(['valid_until' => '2026-10-04 23:59:59'])->isLive($now));
        self::assertTrue(self::coupon(['valid_from' => '2026-10-01 00:00:00', 'valid_until' => '2026-10-31 23:59:59'])->isLive($now));
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private static function coupon(array $row): Coupon
    {
        return Coupon::fromRow([
            'id' => 1, 'code' => 'WELCOME', 'provider_id' => null, 'kind' => 'percent', 'value' => 10,
            'service_ids' => null, 'valid_from' => null, 'valid_until' => null, 'max_uses' => null,
            'once_per_email' => 1, 'active' => 1, ...$row,
        ]);
    }
}
