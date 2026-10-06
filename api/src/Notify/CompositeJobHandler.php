<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

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

    public function handle(array $payload): void
    {
        foreach ($this->handlers as $handler) {
            $handler->handle($payload);
        }
    }
}
