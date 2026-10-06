<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

final class GoogleEvent
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $meetUrl,
        /** Deleted in Google (events keep their id after deletion). */
        public readonly bool $cancelled = false,
    ) {}
}
