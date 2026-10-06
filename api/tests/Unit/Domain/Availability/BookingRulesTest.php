<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Availability;

use ConsultDesk\Domain\Availability\BookingRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingRulesTest extends TestCase
{
    public function testDefaultsMatchThePlan(): void
    {
        $rules = BookingRules::defaults();

        self::assertSame(1440, $rules->minNoticeMinutes);
        self::assertSame(30, $rules->horizonDays);
        self::assertSame(10, $rules->bufferBeforeMinutes);
        self::assertSame(10, $rules->bufferAfterMinutes);
        self::assertSame(30, $rules->slotIntervalMinutes);
        self::assertSame(3, $rules->maxPerDay);
    }

    public function testGapBetweenSessionsIsTheLargerBuffer(): void
    {
        self::assertSame(15, (new BookingRules(0, 30, 5, 15, 30, null))->gapMinutes());
        self::assertSame(20, (new BookingRules(0, 30, 20, 10, 30, null))->gapMinutes());
    }

    /**
     * @return iterable<string, array{int, int, int, int, int, ?int}>
     */
    public static function invalidRules(): iterable
    {
        yield 'negative notice' => [-1, 30, 10, 10, 30, 3];
        yield 'zero horizon' => [0, 0, 10, 10, 30, 3];
        yield 'horizon over a year' => [0, 366, 10, 10, 30, 3];
        yield 'negative buffer before' => [0, 30, -1, 10, 30, 3];
        yield 'negative buffer after' => [0, 30, 10, -1, 30, 3];
        yield 'buffer over 4h' => [0, 30, 241, 10, 30, 3];
        yield 'interval too small' => [0, 30, 10, 10, 4, 3];
        yield 'zero daily cap' => [0, 30, 10, 10, 30, 0];
    }

    #[DataProvider('invalidRules')]
    public function testRejectsInvalidRules(int $notice, int $horizon, int $before, int $after, int $interval, ?int $cap): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BookingRules($notice, $horizon, $before, $after, $interval, $cap);
    }
}
