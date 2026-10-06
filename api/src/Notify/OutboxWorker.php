<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use Throwable;

final class OutboxWorker
{
    /**
     * @param array<string, JobHandler> $handlers keyed by job type
     */
    public function __construct(
        private readonly Outbox $outbox,
        private readonly array $handlers,
    ) {}

    public function run(int $limit): WorkerResult
    {
        $succeeded = 0;
        $failed = 0;

        foreach ($this->outbox->claimDue($limit) as $job) {
            $handler = $this->handlers[$job->type] ?? null;
            if ($handler === null) {
                $this->outbox->failPermanently($job, sprintf('No handler for job type "%s".', $job->type));
                $failed++;

                continue;
            }

            try {
                $handler->handle($job->payload);
                $this->outbox->complete($job);
                $succeeded++;
            } catch (Throwable $e) {
                $this->outbox->fail($job, $e->getMessage());
                $failed++;
            }
        }

        return new WorkerResult($succeeded, $failed);
    }
}
