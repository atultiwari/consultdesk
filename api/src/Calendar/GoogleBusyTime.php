<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Availability\BusyTimeSource;
use ConsultDesk\Domain\Availability\Interval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Google free/busy as a BusyTimeSource, fetched and cached per UTC week so that any requested range
 * maps onto a handful of shared cache entries (callers clip ranges to the bookable window first).
 *
 * If Google cannot be reached or access was revoked, booking stays open: only the provider's own
 * bookings block time until Google is back.
 */
final class GoogleBusyTime implements BusyTimeSource
{
    public function __construct(
        private readonly GoogleCalendar $calendar,
        private readonly GoogleBusyCache $cache,
    ) {}

    public function busy(int $providerId, Interval $range): array
    {
        $busy = [];
        foreach (self::weeks($range) as $week) {
            $weekBusy = $this->cache->get($providerId, $week);
            if ($weekBusy === null) {
                try {
                    $weekBusy = $this->calendar->busy($providerId, $week);
                    $this->cache->put($providerId, $week, $weekBusy);
                } catch (CalendarDisconnected) {
                    return [];
                } catch (GoogleApiError $e) {
                    error_log('[consultdesk] Google free/busy unavailable, booking stays open: ' . $e->getMessage());
                    $this->cache->put($providerId, $week, null);
                    $weekBusy = [];
                }
            }
            array_push($busy, ...$weekBusy);
        }

        return array_values(array_filter($busy, static fn(Interval $i): bool => $i->overlaps($range)));
    }

    /**
     * UTC weeks (Monday 00:00 to Monday 00:00) covering the range.
     *
     * @return list<Interval>
     */
    private static function weeks(Interval $range): array
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($range->start->setTimezone($utc)->format('Y-m-d'), $utc);
        $start = $start->modify(sprintf('-%d days', (int) $start->format('N') - 1));

        $weeks = [];
        for ($week = $start; $week < $range->end; $week = $week->modify('+7 days')) {
            $weeks[] = new Interval($week, $week->modify('+7 days'));
        }

        return $weeks;
    }
}
