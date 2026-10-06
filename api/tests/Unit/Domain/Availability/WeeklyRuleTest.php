<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Availability;

use ConsultDesk\Domain\Availability\WeeklyRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeeklyRuleTest extends TestCase
{
    public function testAcceptsValidRuleAndTrimsSeconds(): void
    {
        $rule = new WeeklyRule(1, '09:00:00', '13:30', 7);

        self::assertSame(1, $rule->weekday);
        self::assertSame('09:00', $rule->startTime);
        self::assertSame('13:30', $rule->endTime);
        self::assertSame(7, $rule->serviceId);
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function invalidRules(): iterable
    {
        yield 'weekday 0' => [0, '09:00', '10:00'];
        yield 'weekday 8' => [8, '09:00', '10:00'];
        yield 'bad start' => [1, '9am', '10:00'];
        yield 'bad end' => [1, '09:00', '25:00'];
        yield 'end before start' => [1, '10:00', '09:00'];
        yield 'empty window' => [1, '10:00', '10:00'];
    }

    #[DataProvider('invalidRules')]
    public function testRejectsInvalidRule(int $weekday, string $start, string $end): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WeeklyRule($weekday, $start, $end);
    }
}
