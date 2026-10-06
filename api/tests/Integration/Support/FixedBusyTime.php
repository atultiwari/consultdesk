<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Support;

use ConsultDesk\Domain\Availability\BusyTimeSource;
use ConsultDesk\Domain\Availability\Interval;

final class FixedBusyTime implements BusyTimeSource
{
    /**
     * @param list<Interval> $intervals
     */
    public function __construct(private readonly array $intervals) {}

    public function busy(int $providerId, Interval $range): array
    {
        return array_values(array_filter($this->intervals, static fn(Interval $i): bool => $i->overlaps($range)));
    }
}
