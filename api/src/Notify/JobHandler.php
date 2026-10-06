<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

interface JobHandler
{
    /**
     * Performs one side effect. Throw to have the job retried later.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
