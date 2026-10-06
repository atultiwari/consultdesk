<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

final class WorkerResult
{
    public function __construct(
        public readonly int $succeeded,
        public readonly int $failed,
    ) {}
}
