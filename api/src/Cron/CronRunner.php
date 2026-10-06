<?php

declare(strict_types=1);

namespace ConsultDesk\Cron;

use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Infra\RateLimiter;
use ConsultDesk\Notify\OutboxWorker;
use PDO;

/**
 * The one scheduled task (hPanel cron, every minute): expire lapsed holds, work through the outbox
 * for up to $budgetSeconds, and prune old rate-limit counters. A server lock skips overlapping runs.
 */
final class CronRunner
{
    private const LOCK_NAME = 'consultdesk_cron';
    private const BATCH_SIZE = 25;

    public function __construct(
        private readonly PDO $pdo,
        private readonly BookingService $bookings,
        private readonly OutboxWorker $worker,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function run(float $budgetSeconds = 20.0): CronReport
    {
        if (!$this->lock()) {
            return new CronReport(ran: false);
        }

        try {
            $expired = $this->bookings->expireStale();
            $deadline = microtime(true) + $budgetSeconds;
            $succeeded = 0;
            $failed = 0;
            do {
                $result = $this->worker->run(self::BATCH_SIZE);
                $succeeded += $result->succeeded;
                $failed += $result->failed;
            } while ($result->succeeded + $result->failed > 0 && microtime(true) < $deadline);

            return new CronReport(true, $expired, $succeeded, $failed, $this->rateLimiter->prune());
        } finally {
            $this->unlock();
        }
    }

    private function lock(): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute(['name' => self::LOCK_NAME]);
        $acquired = (int) $statement->fetchColumn() === 1;
        $statement->closeCursor();

        return $acquired;
    }

    private function unlock(): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $statement->execute(['name' => self::LOCK_NAME]);
        $statement->closeCursor();
    }
}
