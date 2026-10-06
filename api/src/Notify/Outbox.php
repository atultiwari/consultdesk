<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use ConsultDesk\Infra\Clock;
use DateTimeImmutable;
use PDO;

/**
 * Durable queue for side effects (docs/PLAN.md §6.5). Jobs are written in the same transaction as
 * the change that causes them and run later by cron, so a mail or API outage never loses a booking.
 * Designed for a single worker at a time (cron holds a lock).
 */
final class Outbox
{
    public const MAX_ATTEMPTS = 8;
    private const MAX_BACKOFF_MINUTES = 360;
    /** A job still "running" after this long was interrupted (e.g. PHP timed out) and is retried. */
    private const STALE_RUNNING_MINUTES = 15;
    private const SQL_DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param string|null $dedupeKey a second job with the same key is silently dropped
     */
    public function enqueue(string $type, array $payload, ?string $dedupeKey = null, ?DateTimeImmutable $availableAt = null): void
    {
        $now = $this->clock->now();
        $this->pdo->prepare(
            'INSERT INTO outbox_jobs (type, payload, dedupe_key, status, attempts, available_at, created_at, updated_at)
             VALUES (:type, :payload, :dedupe, :status, 0, :available_at, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE id = id',
        )->execute([
            'type' => $type,
            'payload' => json_encode((object) $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'dedupe' => $dedupeKey,
            'status' => 'pending',
            'available_at' => ($availableAt ?? $now)->format(self::SQL_DATETIME),
            'created_at' => $now->format(self::SQL_DATETIME),
            'updated_at' => $now->format(self::SQL_DATETIME),
        ]);
    }

    /**
     * Marks up to $limit due jobs as running and returns them, oldest first.
     *
     * @return list<OutboxJob>
     */
    public function claimDue(int $limit): array
    {
        $now = $this->clock->now();
        $this->releaseStale($now);

        $select = $this->pdo->prepare(
            "SELECT id, type, payload, attempts FROM outbox_jobs
             WHERE status = 'pending' AND available_at <= :now ORDER BY id LIMIT :limit",
        );
        $select->bindValue('now', $now->format(self::SQL_DATETIME));
        $select->bindValue('limit', $limit, PDO::PARAM_INT);
        $select->execute();

        $claim = $this->pdo->prepare(
            "UPDATE outbox_jobs SET status = 'running', attempts = attempts + 1, updated_at = :now
             WHERE id = :id AND status = 'pending'",
        );

        $jobs = [];
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $claim->execute(['now' => $now->format(self::SQL_DATETIME), 'id' => $row['id']]);
            if ($claim->rowCount() === 1) {
                $payload = json_decode((string) $row['payload'], true, 32, JSON_THROW_ON_ERROR);
                $jobs[] = new OutboxJob((int) $row['id'], (string) $row['type'], is_array($payload) ? $payload : [], (int) $row['attempts'] + 1);
            }
        }

        return $jobs;
    }

    public function complete(OutboxJob $job): void
    {
        $this->finish($job, 'done', null, $this->clock->now());
    }

    /**
     * Schedules a retry with exponential backoff, or marks the job failed after MAX_ATTEMPTS.
     */
    public function fail(OutboxJob $job, string $error): void
    {
        if ($job->attempts >= self::MAX_ATTEMPTS) {
            $this->failPermanently($job, $error);

            return;
        }

        $delay = min(2 ** $job->attempts, self::MAX_BACKOFF_MINUTES);
        $this->finish($job, 'pending', $error, $this->clock->now()->modify(sprintf('+%d minutes', $delay)));
    }

    public function failPermanently(OutboxJob $job, string $error): void
    {
        $this->finish($job, 'failed', $error, $this->clock->now());
    }

    private function finish(OutboxJob $job, string $status, ?string $error, DateTimeImmutable $availableAt): void
    {
        $this->pdo->prepare(
            'UPDATE outbox_jobs SET status = :status, last_error = :error, available_at = :available_at, updated_at = :now
             WHERE id = :id',
        )->execute([
            'status' => $status,
            'error' => $error === null ? null : mb_substr($error, 0, 2000),
            'available_at' => $availableAt->format(self::SQL_DATETIME),
            'now' => $this->clock->now()->format(self::SQL_DATETIME),
            'id' => $job->id,
        ]);
    }

    private function releaseStale(DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            "UPDATE outbox_jobs SET status = 'pending' WHERE status = 'running' AND updated_at <= :cutoff",
        )->execute(['cutoff' => $now->modify(sprintf('-%d minutes', self::STALE_RUNNING_MINUTES))->format(self::SQL_DATETIME)]);
    }
}
