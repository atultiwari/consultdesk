<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Availability;

final class NoBusyTime implements BusyTimeSource
{
    public function busy(int $providerId, Interval $range): array
    {
        return [];
    }
}
