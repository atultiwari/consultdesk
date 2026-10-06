<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock fixed at one instant, for tests and for replaying cron runs.
 */
final class FrozenClock implements Clock
{
    private readonly DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|string $now)
    {
        $instant = is_string($now) ? new DateTimeImmutable($now) : $now;
        $this->now = $instant->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
