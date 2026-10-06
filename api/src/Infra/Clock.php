<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use DateTimeImmutable;

interface Clock
{
    /**
     * Current time in UTC.
     */
    public function now(): DateTimeImmutable;
}
