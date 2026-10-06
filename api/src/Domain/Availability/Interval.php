<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A half-open time range [start, end), always held in UTC.
 */
final class Interval
{
    public readonly DateTimeImmutable $start;
    public readonly DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        if ($end <= $start) {
            throw new InvalidArgumentException('Interval end must be after its start.');
        }

        $utc = new DateTimeZone('UTC');
        $this->start = $start->setTimezone($utc);
        $this->end = $end->setTimezone($utc);
    }

    public static function fromStrings(string $start, string $end): self
    {
        return new self(new DateTimeImmutable($start), new DateTimeImmutable($end));
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function contains(self $other): bool
    {
        return $this->start <= $other->start && $other->end <= $this->end;
    }

    public function pad(int $beforeMinutes, int $afterMinutes): self
    {
        return new self(
            $this->start->modify(sprintf('-%d minutes', $beforeMinutes)),
            $this->end->modify(sprintf('+%d minutes', $afterMinutes)),
        );
    }

    public function minutes(): int
    {
        return intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60);
    }

    /**
     * Sorts intervals and joins any that overlap or touch.
     *
     * @param list<self> $intervals
     *
     * @return list<self>
     */
    public static function merge(array $intervals): array
    {
        usort($intervals, static fn(self $a, self $b): int => $a->start <=> $b->start);

        $merged = [];
        foreach ($intervals as $interval) {
            $last = array_key_last($merged);
            if ($last !== null && $interval->start <= $merged[$last]->end) {
                $end = max($merged[$last]->end, $interval->end);
                $merged[$last] = new self($merged[$last]->start, $end);

                continue;
            }
            $merged[] = $interval;
        }

        return $merged;
    }
}
