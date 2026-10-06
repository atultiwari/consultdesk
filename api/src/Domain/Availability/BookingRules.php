<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Availability;

use InvalidArgumentException;

/**
 * Per-provider booking rules. Defaults follow docs/PLAN.md §8.
 */
final class BookingRules
{
    private const MAX_HORIZON_DAYS = 365;
    private const MAX_BUFFER_MINUTES = 240;
    private const MIN_SLOT_INTERVAL_MINUTES = 5;

    public function __construct(
        public readonly int $minNoticeMinutes,
        public readonly int $horizonDays,
        public readonly int $bufferBeforeMinutes,
        public readonly int $bufferAfterMinutes,
        public readonly int $slotIntervalMinutes,
        public readonly ?int $maxPerDay,
    ) {
        self::assertRange($minNoticeMinutes, 0, PHP_INT_MAX, 'Minimum notice');
        self::assertRange($horizonDays, 1, self::MAX_HORIZON_DAYS, 'Horizon');
        self::assertRange($bufferBeforeMinutes, 0, self::MAX_BUFFER_MINUTES, 'Buffer before');
        self::assertRange($bufferAfterMinutes, 0, self::MAX_BUFFER_MINUTES, 'Buffer after');
        self::assertRange($slotIntervalMinutes, self::MIN_SLOT_INTERVAL_MINUTES, 24 * 60, 'Slot interval');
        if ($maxPerDay !== null) {
            self::assertRange($maxPerDay, 1, 1000, 'Max per day');
        }
    }

    public static function defaults(): self
    {
        return new self(
            minNoticeMinutes: 24 * 60,
            horizonDays: 30,
            bufferBeforeMinutes: 10,
            bufferAfterMinutes: 10,
            slotIntervalMinutes: 30,
            maxPerDay: 3,
        );
    }

    /**
     * Minimum free time between two of this provider's sessions: the larger of the two buffers.
     */
    public function gapMinutes(): int
    {
        return max($this->bufferBeforeMinutes, $this->bufferAfterMinutes);
    }

    private static function assertRange(int $value, int $min, int $max, string $label): void
    {
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d.', $label, $min, $max));
        }
    }
}
