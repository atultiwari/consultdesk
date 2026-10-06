<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Availability;

use ConsultDesk\Domain\Booking\BookingRepository;
use ConsultDesk\Domain\Booking\ServiceNotBookable;
use ConsultDesk\Infra\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Loads everything SlotEngine needs for one provider and service and returns the open slots.
 * Read-only: BookingService::hold() re-checks the chosen slot under a lock.
 */
final class SlotFinder
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly BusyTimeSource $busy,
        private readonly Clock $clock,
        private readonly SlotEngine $engine = new SlotEngine(),
    ) {}

    /**
     * @return list<Interval>
     */
    public function find(int $providerId, int $serviceId, int $durationMinutes, string $fromDate, string $toDate): array
    {
        $provider = $this->bookings->findActiveProvider($providerId) ?? throw new ServiceNotBookable();
        $now = $this->clock->now();
        $range = $this->localRange($provider->timezone, $fromDate, $toDate);

        return $this->engine->slots(new SlotRequest(
            timezone: $provider->timezone->getName(),
            rules: $provider->rules,
            weeklyRules: $this->bookings->weeklyRules($providerId),
            serviceId: $serviceId,
            durationMinutes: $durationMinutes,
            fromDate: $fromDate,
            toDate: $toDate,
            now: $now,
            blocked: $this->bookings->blockedPeriods($providerId, $range),
            bookings: $this->bookings->blockingIntervals($providerId, $range, $now),
            busy: $this->busyIn($providerId, $range, $now, $provider->rules),
        ));
    }

    /**
     * @return list<Interval>
     */
    private function busyIn(int $providerId, Interval $range, DateTimeImmutable $now, BookingRules $rules): array
    {
        $window = BusyWindow::clip($range, $now, $rules);

        return $window === null ? [] : $this->busy->busy($providerId, $window);
    }

    /**
     * The local date range widened by a day each side, so gaps and buffers across midnight are seen.
     */
    private function localRange(DateTimeZone $timezone, string $fromDate, string $toDate): Interval
    {
        return new Interval(
            (new DateTimeImmutable($fromDate, $timezone))->modify('-1 day'),
            (new DateTimeImmutable($toDate, $timezone))->modify('+2 days'),
        );
    }
}
