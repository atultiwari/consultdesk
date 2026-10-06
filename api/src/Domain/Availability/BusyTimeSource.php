<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Availability;

/**
 * External busy time for a provider, e.g. Google Calendar free/busy (Phase 4).
 */
interface BusyTimeSource
{
    /**
     * @return list<Interval>
     */
    public function busy(int $providerId, Interval $range): array;
}
