<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Availability;

use DateTimeImmutable;

/**
 * The part of a range worth asking an external calendar about: from a day before now to a day
 * past the booking horizon. Anything outside can never be booked, so it never needs busy time —
 * which also stops arbitrary date ranges from turning into calls to Google.
 */
final class BusyWindow
{
    public static function clip(Interval $range, DateTimeImmutable $now, BookingRules $rules): ?Interval
    {
        $start = max($range->start, $now->modify('-1 day'));
        $end = min($range->end, $now->modify(sprintf('+%d days', $rules->horizonDays + 1)));

        return $end > $start ? new Interval($start, $end) : null;
    }
}
