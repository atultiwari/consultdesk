<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Availability;

use DateTimeImmutable;

/**
 * Pure slot calculator (docs/PLAN.md §6.1). No I/O, no clock: everything comes in through SlotRequest.
 *
 * For each local day it takes the weekly windows, steps through them at the slot interval and keeps a
 * candidate only if it:
 *  - starts no earlier than now + minimum notice and no later than now + horizon;
 *  - does not overlap a blocked period;
 *  - leaves gapMinutes() (the larger buffer) free on both sides of every existing booking;
 *  - with its own before/after buffers, does not overlap external busy time;
 *  - falls on a day that has not reached max-per-day.
 */
final class SlotEngine
{
    /**
     * @return list<Interval> open slots in UTC, sorted by start
     */
    public function slots(SlotRequest $request): array
    {
        $rules = $request->rules;
        $earliest = $request->now->modify(sprintf('+%d minutes', $rules->minNoticeMinutes));
        $latest = $request->now->modify(sprintf('+%d days', $rules->horizonDays));
        $bookingsPerDay = $this->countByLocalDay($request);
        $weeklyRules = $this->rulesForService($request->weeklyRules, $request->serviceId);

        $slots = [];
        for ($day = $request->firstDay; $day <= $request->lastDay; $day = $day->modify('+1 day')) {
            if ($rules->maxPerDay !== null && ($bookingsPerDay[$day->format('Y-m-d')] ?? 0) >= $rules->maxPerDay) {
                continue;
            }

            foreach ($this->windowsFor($day, $weeklyRules) as $window) {
                foreach ($this->candidates($window, $request) as $candidate) {
                    if ($candidate->start >= $earliest && $candidate->start <= $latest && $this->isFree($candidate, $request)) {
                        $slots[] = $candidate;
                    }
                }
            }
        }

        return $slots;
    }

    /**
     * A service's own rules replace the provider's general rules.
     *
     * @param list<WeeklyRule> $rules
     *
     * @return list<WeeklyRule>
     */
    private function rulesForService(array $rules, int $serviceId): array
    {
        $own = array_values(array_filter($rules, static fn(WeeklyRule $r): bool => $r->serviceId === $serviceId));

        return $own !== [] ? $own : array_values(array_filter($rules, static fn(WeeklyRule $r): bool => $r->serviceId === null));
    }

    /**
     * @param list<WeeklyRule> $rules
     *
     * @return list<Interval>
     */
    private function windowsFor(DateTimeImmutable $day, array $rules): array
    {
        $weekday = (int) $day->format('N');
        $date = $day->format('Y-m-d');
        $windows = [];

        foreach ($rules as $rule) {
            if ($rule->weekday === $weekday) {
                // Built from local wall-clock times, so DST shifts land correctly in UTC.
                $windows[] = new Interval(
                    new DateTimeImmutable("{$date} {$rule->startTime}", $day->getTimezone()),
                    new DateTimeImmutable("{$date} {$rule->endTime}", $day->getTimezone()),
                );
            }
        }

        return Interval::merge($windows);
    }

    /**
     * @return iterable<Interval>
     */
    private function candidates(Interval $window, SlotRequest $request): iterable
    {
        $step = sprintf('+%d minutes', $request->rules->slotIntervalMinutes);
        $length = sprintf('+%d minutes', $request->durationMinutes);

        for ($start = $window->start; $start < $window->end; $start = $start->modify($step)) {
            $end = $start->modify($length);
            if ($end > $window->end) {
                return;
            }
            yield new Interval($start, $end);
        }
    }

    private function isFree(Interval $candidate, SlotRequest $request): bool
    {
        $rules = $request->rules;
        $withGap = $candidate->pad($rules->gapMinutes(), $rules->gapMinutes());
        $withOwnBuffers = $candidate->pad($rules->bufferBeforeMinutes, $rules->bufferAfterMinutes);

        return !$this->overlapsAny($candidate, $request->blocked)
            && !$this->overlapsAny($withGap, $request->bookings)
            && !$this->overlapsAny($withOwnBuffers, $request->busy);
    }

    /**
     * @param list<Interval> $intervals
     */
    private function overlapsAny(Interval $candidate, array $intervals): bool
    {
        foreach ($intervals as $interval) {
            if ($candidate->overlaps($interval)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int> booking counts keyed by local date (Y-m-d)
     */
    private function countByLocalDay(SlotRequest $request): array
    {
        $counts = [];
        foreach ($request->bookings as $booking) {
            $key = $booking->start->setTimezone($request->timezone)->format('Y-m-d');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
