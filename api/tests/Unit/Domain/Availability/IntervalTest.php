<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Availability;

use ConsultDesk\Domain\Availability\Interval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class IntervalTest extends TestCase
{
    public function testNormalisesToUtc(): void
    {
        $interval = new Interval(
            new DateTimeImmutable('2026-10-05 10:00', new DateTimeZone('Asia/Kolkata')),
            new DateTimeImmutable('2026-10-05 11:00', new DateTimeZone('Asia/Kolkata')),
        );

        self::assertSame('2026-10-05T04:30:00+00:00', $interval->start->format(DATE_ATOM));
        self::assertSame('2026-10-05T05:30:00+00:00', $interval->end->format(DATE_ATOM));
        self::assertSame(60, $interval->minutes());
    }

    public function testRejectsEmptyOrInvertedInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Interval::fromStrings('2026-10-05T10:00Z', '2026-10-05T10:00Z');
    }

    public function testOverlapIsHalfOpen(): void
    {
        $a = Interval::fromStrings('2026-10-05T10:00Z', '2026-10-05T11:00Z');

        self::assertTrue($a->overlaps(Interval::fromStrings('2026-10-05T10:59Z', '2026-10-05T12:00Z')));
        self::assertTrue($a->overlaps(Interval::fromStrings('2026-10-05T09:00Z', '2026-10-05T10:01Z')));
        self::assertFalse($a->overlaps(Interval::fromStrings('2026-10-05T11:00Z', '2026-10-05T12:00Z')));
        self::assertFalse($a->overlaps(Interval::fromStrings('2026-10-05T09:00Z', '2026-10-05T10:00Z')));
    }

    public function testContains(): void
    {
        $window = Interval::fromStrings('2026-10-05T09:00Z', '2026-10-05T12:00Z');

        self::assertTrue($window->contains(Interval::fromStrings('2026-10-05T09:00Z', '2026-10-05T12:00Z')));
        self::assertFalse($window->contains(Interval::fromStrings('2026-10-05T11:30Z', '2026-10-05T12:30Z')));
    }

    public function testPadReturnsNewWiderInterval(): void
    {
        $a = Interval::fromStrings('2026-10-05T10:00Z', '2026-10-05T11:00Z');
        $padded = $a->pad(10, 15);

        self::assertSame('2026-10-05T09:50:00+00:00', $padded->start->format(DATE_ATOM));
        self::assertSame('2026-10-05T11:15:00+00:00', $padded->end->format(DATE_ATOM));
        self::assertSame('2026-10-05T10:00:00+00:00', $a->start->format(DATE_ATOM), 'original is untouched');
    }

    public function testMergeSortsAndJoinsOverlappingAndTouchingIntervals(): void
    {
        $merged = Interval::merge([
            Interval::fromStrings('2026-10-05T13:00Z', '2026-10-05T14:00Z'),
            Interval::fromStrings('2026-10-05T09:00Z', '2026-10-05T10:00Z'),
            Interval::fromStrings('2026-10-05T09:30Z', '2026-10-05T11:00Z'),
            Interval::fromStrings('2026-10-05T11:00Z', '2026-10-05T12:00Z'),
        ]);

        self::assertSame(
            [['09:00', '12:00'], ['13:00', '14:00']],
            array_map(static fn(Interval $i): array => [$i->start->format('H:i'), $i->end->format('H:i')], $merged),
        );
        self::assertSame([], Interval::merge([]));
    }
}
