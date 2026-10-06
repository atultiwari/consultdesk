<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * Everything SlotEngine needs to compute open slots for one provider and service.
 * Dates are calendar days in the provider's timezone; intervals are UTC.
 */
final class SlotRequest
{
    public const MAX_RANGE_DAYS = 62;

    public readonly DateTimeZone $timezone;
    public readonly DateTimeImmutable $firstDay;
    public readonly DateTimeImmutable $lastDay;

    /**
     * @param list<WeeklyRule> $weeklyRules all of the provider's rules; SlotEngine picks the ones for this service
     * @param list<Interval> $blocked provider and org-wide blocked periods
     * @param list<Interval> $bookings the provider's active bookings (held, awaiting verification, confirmed)
     * @param list<Interval> $busy external busy time, e.g. Google Calendar free/busy
     */
    public function __construct(
        string $timezone,
        public readonly BookingRules $rules,
        public readonly array $weeklyRules,
        public readonly int $serviceId,
        public readonly int $durationMinutes,
        string $fromDate,
        string $toDate,
        public readonly DateTimeImmutable $now,
        public readonly array $blocked = [],
        public readonly array $bookings = [],
        public readonly array $busy = [],
    ) {
        if ($durationMinutes <= 0) {
            throw new InvalidArgumentException('Duration must be positive.');
        }

        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (Exception) {
            throw new InvalidArgumentException(sprintf('Unknown timezone "%s".', $timezone));
        }

        $this->firstDay = $this->parseDate($fromDate);
        $this->lastDay = $this->parseDate($toDate);

        if ($this->lastDay < $this->firstDay) {
            throw new InvalidArgumentException('Date range ends before it starts.');
        }
        if ($this->firstDay->diff($this->lastDay)->days >= self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException(sprintf('Date range may span at most %d days.', self::MAX_RANGE_DAYS));
        }
    }

    private function parseDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s"; expected YYYY-MM-DD.', $date));
        }

        return $parsed;
    }
}
