<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Migrator;
use ConsultDesk\Infra\Settings;
use ConsultDesk\Version;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * What the owner's System panel shows: version, pending database updates, whether cron is running
 * and how the outbox (emails, calendar, Telegram) is doing.
 */
final class SystemStatus
{
    public const CRON_KEY = 'cron';
    /** Cron runs every minute; five quiet minutes means it has stopped. */
    private const CRON_HEALTHY_SECONDS = 5 * 60;
    private const ISO = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Settings $settings,
        private readonly Migrator $migrator,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $lastRun = $this->settings->get(self::CRON_KEY)['last_run_at'] ?? null;
        $lastRunAt = is_string($lastRun) ? new DateTimeImmutable($lastRun, new DateTimeZone('UTC')) : null;
        $now = $this->clock->now();

        return [
            'version' => Version::CURRENT,
            'php' => PHP_VERSION,
            'migrations_pending' => $this->migrator->pending(),
            'cron' => [
                'last_run_at' => $lastRunAt?->format(self::ISO),
                'healthy' => $lastRunAt !== null && $now->getTimestamp() - $lastRunAt->getTimestamp() <= self::CRON_HEALTHY_SECONDS,
            ],
            'outbox' => $this->outbox(),
        ];
    }

    /**
     * @return list<string> the migrations applied
     */
    public function migrate(): array
    {
        return $this->migrator->migrate();
    }

    /**
     * Puts failed outbox jobs back in the queue for the next cron run.
     */
    public function retryFailed(): int
    {
        $statement = $this->pdo->prepare("UPDATE outbox_jobs SET status = 'pending', attempts = 0, available_at = :now WHERE status = 'failed'");
        $statement->execute(['now' => $this->clock->now()->format('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }

    /**
     * @return array{pending: int, failed: int, last_error: ?string}
     */
    private function outbox(): array
    {
        $counts = $this->pdo->query("SELECT
                SUM(status IN ('pending', 'running')) AS pending,
                SUM(status = 'failed') AS failed,
                (SELECT last_error FROM outbox_jobs WHERE status = 'failed' ORDER BY updated_at DESC, id DESC LIMIT 1) AS last_error
            FROM outbox_jobs");
        $row = $counts === false ? [] : ($counts->fetch(PDO::FETCH_ASSOC) ?: []);

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : null,
        ];
    }
}
