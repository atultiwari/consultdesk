<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use Throwable;

/**
 * Runs several handlers for one job type, e.g. email and Telegram fan-out for a booking event.
 * Each handler must be idempotent (fan-out uses dedupe keys), because a failure retries them all.
 */
final class CompositeJobHandler implements JobHandler
{
    /**
     * @param list<JobHandler> $handlers
     */
    public function __construct(private readonly array $handlers) {}

    /**
     * Runs every handler even if one fails, so an email problem never delays a Telegram alert
     * (or the reverse), then rethrows the first failure so the job is retried.
     */
    public function handle(array $payload): void
    {
        $failure = null;
        foreach ($this->handlers as $handler) {
            try {
                $handler->handle($payload);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
