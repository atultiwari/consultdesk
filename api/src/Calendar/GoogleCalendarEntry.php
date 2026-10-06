<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

final class GoogleCalendarEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $summary,
        public readonly bool $primary,
        public readonly bool $writable,
    ) {}
}
