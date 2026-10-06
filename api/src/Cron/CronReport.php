<?php

declare(strict_types=1);

namespace ConsultDesk\Cron;

final class CronReport
{
    public function __construct(
        public readonly bool $ran,
        public readonly int $expired = 0,
        public readonly int $jobsSucceeded = 0,
        public readonly int $jobsFailed = 0,
        public readonly int $rateLimitRowsPruned = 0,
    ) {}

    /**
     * @return array<string, bool|int>
     */
    public function toArray(): array
    {
        return [
            'ran' => $this->ran,
            'expired' => $this->expired,
            'jobs_succeeded' => $this->jobsSucceeded,
            'jobs_failed' => $this->jobsFailed,
            'rate_limit_rows_pruned' => $this->rateLimitRowsPruned,
        ];
    }
}
