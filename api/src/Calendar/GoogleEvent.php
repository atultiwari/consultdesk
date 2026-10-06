<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

final class GoogleEvent
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $meetUrl,
    ) {}
}
