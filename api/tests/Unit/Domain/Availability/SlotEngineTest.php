<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Availability;

use ConsultDesk\Domain\Availability\BookingRules;
use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Domain\Availability\SlotEngine;
use ConsultDesk\Domain\Availability\SlotRequest;
use ConsultDesk\Domain\Availability\WeeklyRule;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 2026-10-05 is a Monday. Asia/Kolkata is UTC+05:30 with no DST.
 */
final class SlotEngineTest extends TestCase
{
    private const SERVICE_ID = 7;

    public function testGeneratesSlotsInsideTheWeeklyWindowAtTheSlotInterval(): void
    {
        $slots = $this->slots(weeklyRules: [new WeeklyRule(1, '10:00', '12:00')]);

        self::assertSame(['2026-10-05T04:30Z', '2026-10-05T05:00Z', '2026-10-05T05:30Z'], $this->starts($slots));
        self::assertSame(60, $slots[0]->minutes());
    }

    public function testOnlyUsesRulesForTheMatchingWeekday(): void
    {
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(2, '10:00', '11:00')],
            from: '2026-10-05',
            to: '2026-10-06',
        );

        self::assertSame(['2026-10-06T04:30Z'], $this->starts($slots));
    }

    public function testExcludesSlotsInsideTheMinimumNotice(): void
    {
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(1, '10:00', '12:00')],
            rules: $this->rules(minNotice: 60),
            now: '2026-10-05T04:00Z', // 09:30 IST → earliest start 10:30 IST
        );

        self::assertSame(['2026-10-05T05:00Z', '2026-10-05T05:30Z'], $this->starts($slots));
    }

    public function testExcludesSlotsBeyondTheHorizon(): void
    {
        $rule = [new WeeklyRule(1, '10:00', '11:00')];

        self::assertSame([], $this->slots(weeklyRules: $rule, rules: $this->rules(horizon: 4), now: '2026-10-01T04:29Z'));
        self::assertCount(1, $this->slots(weeklyRules: $rule, rules: $this->rules(horizon: 4), now: '2026-10-01T04:30Z'));
    }

    public function testKeepsTheLargerBufferAsTheGapAroundExistingBookings(): void
    {
        // before 5, after 15 → required gap is max(5, 15) = 15 minutes on either side.
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(1, '08:00', '14:00')],
            rules: $this->rules(before: 5, after: 15, interval: 5),
            bookings: [$this->ist('10:00', '11:00')],
        );
        $starts = $this->localStarts($slots);

        self::assertContains('08:45', $starts);
        self::assertNotContains('08:50', $starts);
        self::assertContains('11:15', $starts);
        self::assertNotContains('11:10', $starts);
    }

    public function testAppliesOwnBuffersAroundExternalBusyTime(): void
    {
        // Busy calendar events carry no buffers, so this session's own before/after apply.
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(1, '08:00', '14:00')],
            rules: $this->rules(before: 5, after: 15, interval: 5),
            busy: [$this->ist('10:00', '11:00')],
        );
        $starts = $this->localStarts($slots);

        self::assertContains('08:45', $starts);
        self::assertNotContains('08:50', $starts);
        self::assertContains('11:05', $starts);
        self::assertNotContains('11:00', $starts);
    }

    public function testBlockedPeriodsExcludeOverlappingSlotsWithoutBuffers(): void
    {
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(1, '09:00', '13:00')],
            rules: $this->rules(before: 10, after: 10, interval: 5),
            blocked: [$this->ist('10:00', '11:00')],
        );
        $starts = $this->localStarts($slots);

        self::assertContains('09:00', $starts);
        self::assertNotContains('09:05', $starts);
        self::assertContains('11:00', $starts);
    }

    public function testDailyCapCountsBookingsByTheProvidersLocalDate(): void
    {
        // 2026-10-05T20:00Z is 01:30 on Tuesday 6 Oct in IST, so it uses Tuesday's cap.
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(1, '10:00', '11:00'), new WeeklyRule(2, '10:00', '11:00')],
            rules: $this->rules(cap: 1),
            from: '2026-10-05',
            to: '2026-10-06',
            bookings: [Interval::fromStrings('2026-10-05T20:00Z', '2026-10-05T20:30Z')],
        );

        self::assertSame(['2026-10-05T04:30Z'], $this->starts($slots));
    }

    public function testDayUnderTheCapStillOffersSlots(): void
    {
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(1, '10:00', '13:00')],
            rules: $this->rules(cap: 2),
            bookings: [$this->ist('12:00', '13:00')],
        );

        self::assertSame(['10:00', '10:30', '11:00'], $this->localStarts($slots));
    }

    public function testServiceSpecificRulesReplaceTheGeneralRules(): void
    {
        $rules = [new WeeklyRule(1, '10:00', '12:00'), new WeeklyRule(1, '15:00', '16:00', self::SERVICE_ID)];

        self::assertSame(['15:00'], $this->localStarts($this->slots(weeklyRules: $rules)));
        self::assertSame(['10:00', '10:30', '11:00'], $this->localStarts($this->slots(weeklyRules: $rules, serviceId: 99)));
    }

    public function testOverlappingRulesAreMergedWithoutDuplicateSlots(): void
    {
        $slots = $this->slots(weeklyRules: [new WeeklyRule(1, '10:00', '12:00'), new WeeklyRule(1, '11:00', '13:00')]);

        self::assertSame(['10:00', '10:30', '11:00', '11:30', '12:00'], $this->localStarts($slots));
    }

    public function testSpringForwardKeepsLocalWallClockTimes(): void
    {
        // Europe/London moves to BST on Sunday 2026-03-29.
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(6, '09:00', '10:00'), new WeeklyRule(7, '09:00', '10:00')],
            timezone: 'Europe/London',
            from: '2026-03-28',
            to: '2026-03-29',
            now: '2026-03-01T00:00Z',
        );

        self::assertSame(['2026-03-28T09:00Z', '2026-03-29T08:00Z'], $this->starts($slots));
    }

    public function testFallBackKeepsLocalWallClockTimes(): void
    {
        // America/New_York returns to EST on Sunday 2026-11-01.
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(6, '09:00', '10:00'), new WeeklyRule(7, '09:00', '10:00')],
            timezone: 'America/New_York',
            from: '2026-10-31',
            to: '2026-11-01',
        );

        self::assertSame(['2026-10-31T13:00Z', '2026-11-01T14:00Z'], $this->starts($slots));
    }

    public function testWindowAcrossTheSpringForwardGapHasOnlyRealHours(): void
    {
        // 00:00–04:00 local on the change-over night is only three real hours.
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(7, '00:00', '04:00')],
            rules: $this->rules(interval: 60),
            timezone: 'Europe/London',
            from: '2026-03-29',
            to: '2026-03-29',
            now: '2026-03-01T00:00Z',
        );

        self::assertSame(['2026-03-29T00:00Z', '2026-03-29T01:00Z', '2026-03-29T02:00Z'], $this->starts($slots));
    }

    public function testWindowEntirelyInsideTheSpringForwardGapIsSkipped(): void
    {
        // 01:00–02:00 does not exist in London on 2026-03-29; other days still work.
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(7, '01:00', '01:45'), new WeeklyRule(6, '01:00', '01:45')],
            rules: $this->rules(interval: 15),
            timezone: 'Europe/London',
            from: '2026-03-28',
            to: '2026-03-29',
            now: '2026-03-01T00:00Z',
            duration: 30,
        );

        self::assertSame(['2026-03-28T01:00Z', '2026-03-28T01:15Z'], $this->starts($slots));
    }

    public function testWindowStartingInTheGapStartsAtTheClockChange(): void
    {
        // New York jumps from 02:00 EST to 03:00 EDT (07:00Z) on 2027-03-14.
        $gapOnly = [new WeeklyRule(7, '02:00', '03:00')];
        $straddling = [new WeeklyRule(7, '02:30', '04:00')];
        $args = ['timezone' => 'America/New_York', 'from' => '2027-03-14', 'to' => '2027-03-14', 'now' => '2027-03-01T00:00Z'];

        self::assertSame([], $this->slots(...['weeklyRules' => $gapOnly, ...$args]));
        self::assertSame(['2027-03-14T07:00Z'], $this->starts($this->slots(...['weeklyRules' => $straddling, ...$args])));
    }

    public function testResultsAreSortedAcrossDays(): void
    {
        $slots = $this->slots(
            weeklyRules: [new WeeklyRule(2, '10:00', '11:00'), new WeeklyRule(1, '15:00', '16:00')],
            from: '2026-10-05',
            to: '2026-10-06',
        );

        self::assertSame(['2026-10-05T09:30Z', '2026-10-06T04:30Z'], $this->starts($slots));
    }

    public function testRejectsAnInvertedDateRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->slots(weeklyRules: [], from: '2026-10-06', to: '2026-10-05');
    }

    public function testRejectsARangeLongerThanTheMaximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->slots(weeklyRules: [], from: '2026-10-01', to: '2027-01-01');
    }

    public function testRejectsAMalformedDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->slots(weeklyRules: [], from: '2026-13-01', to: '2026-13-02');
    }

    public function testRejectsANonPositiveDuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->slots(weeklyRules: [], duration: 0);
    }

    public function testRejectsAnUnknownTimezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->slots(weeklyRules: [], timezone: 'Mars/Olympus_Mons');
    }

    /**
     * @param list<WeeklyRule> $weeklyRules
     * @param list<Interval> $blocked
     * @param list<Interval> $bookings
     * @param list<Interval> $busy
     *
     * @return list<Interval>
     */
    private function slots(
        array $weeklyRules,
        ?BookingRules $rules = null,
        string $timezone = 'Asia/Kolkata',
        string $from = '2026-10-05',
        string $to = '2026-10-05',
        string $now = '2026-10-01T00:00Z',
        int $duration = 60,
        int $serviceId = self::SERVICE_ID,
        array $blocked = [],
        array $bookings = [],
        array $busy = [],
    ): array {
        return (new SlotEngine())->slots(new SlotRequest(
            timezone: $timezone,
            rules: $rules ?? $this->rules(),
            weeklyRules: $weeklyRules,
            serviceId: $serviceId,
            durationMinutes: $duration,
            fromDate: $from,
            toDate: $to,
            now: new DateTimeImmutable($now),
            blocked: $blocked,
            bookings: $bookings,
            busy: $busy,
        ));
    }

    private function rules(int $minNotice = 0, int $horizon = 365, int $before = 0, int $after = 0, int $interval = 30, ?int $cap = null): BookingRules
    {
        return new BookingRules($minNotice, $horizon, $before, $after, $interval, $cap);
    }

    private function ist(string $start, string $end, string $date = '2026-10-05'): Interval
    {
        $tz = new DateTimeZone('Asia/Kolkata');

        return new Interval(new DateTimeImmutable("{$date} {$start}", $tz), new DateTimeImmutable("{$date} {$end}", $tz));
    }

    /**
     * @param list<Interval> $slots
     *
     * @return list<string>
     */
    private function starts(array $slots): array
    {
        return array_map(static fn(Interval $s): string => $s->start->format('Y-m-d\TH:i\Z'), $slots);
    }

    /**
     * @param list<Interval> $slots
     *
     * @return list<string>
     */
    private function localStarts(array $slots): array
    {
        $tz = new DateTimeZone('Asia/Kolkata');

        return array_map(static fn(Interval $s): string => $s->start->setTimezone($tz)->format('H:i'), $slots);
    }
}
