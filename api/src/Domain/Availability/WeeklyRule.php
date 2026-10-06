<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Availability;

use InvalidArgumentException;

/**
 * A recurring weekly window in the provider's local time.
 * Weekday follows ISO-8601: 1 = Monday … 7 = Sunday.
 * A rule with a serviceId applies only to that service (see SlotEngine).
 */
final class WeeklyRule
{
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/';

    public readonly string $startTime;
    public readonly string $endTime;

    public function __construct(
        public readonly int $weekday,
        string $startTime,
        string $endTime,
        public readonly ?int $serviceId = null,
    ) {
        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException('Weekday must be between 1 (Monday) and 7 (Sunday).');
        }

        $this->startTime = self::normaliseTime($startTime);
        $this->endTime = self::normaliseTime($endTime);

        if ($this->endTime <= $this->startTime) {
            throw new InvalidArgumentException('Weekly rule must end after it starts.');
        }
    }

    private static function normaliseTime(string $time): string
    {
        if (preg_match(self::TIME_PATTERN, $time) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid time "%s"; expected HH:MM.', $time));
        }

        return substr($time, 0, 5);
    }
}
