<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Availability\BusyTimeSource;
use ConsultDesk\Domain\Availability\Interval;

/**
 * Google free/busy as a BusyTimeSource. If Google cannot be reached or access was revoked,
 * booking stays open: only this provider's own bookings block time until Google is back.
 */
final class GoogleBusyTime implements BusyTimeSource
{
    public function __construct(
        private readonly GoogleCalendar $calendar,
        private readonly GoogleBusyCache $cache,
    ) {}

    public function busy(int $providerId, Interval $range): array
    {
        $cached = $this->cache->get($providerId, $range);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $busy = $this->calendar->busy($providerId, $range);
        } catch (CalendarDisconnected) {
            return [];
        } catch (GoogleApiError $e) {
            error_log('[consultdesk] Google free/busy unavailable, booking stays open: ' . $e->getMessage());

            return [];
        }

        $this->cache->put($providerId, $range, $busy);

        return $busy;
    }
}
