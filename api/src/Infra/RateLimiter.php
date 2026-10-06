<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use PDO;

/**
 * Fixed-window request counter in the database (shared hosting has no Redis or APCu guarantee).
 * Subjects (client IPs) are stored only as keyed hashes.
 */
final class RateLimiter
{
    private const SQL_DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        #[\SensitiveParameter]
        private readonly string $hashKey,
    ) {}

    /**
     * Counts one hit and reports whether it is within the limit.
     *
     * @return int|null seconds until the window resets when over the limit, otherwise null
     */
    public function hit(string $bucket, string $subject, int $limit, int $windowSeconds): ?int
    {
        $now = $this->clock->now()->getTimestamp();
        $windowStart = $now - ($now % $windowSeconds);

        $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, subject, window_start, hits) VALUES (:bucket, :subject, :window_start, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
        )->execute($this->key($bucket, $subject, $windowStart));

        $statement = $this->pdo->prepare(
            'SELECT hits FROM rate_limits WHERE bucket = :bucket AND subject = :subject AND window_start = :window_start',
        );
        $statement->execute($this->key($bucket, $subject, $windowStart));
        $hits = (int) $statement->fetchColumn();

        return $hits > $limit ? max(1, $windowStart + $windowSeconds - $now) : null;
    }

    /**
     * Deletes counters for windows that ended more than a day ago.
     */
    public function prune(): int
    {
        $statement = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < :cutoff');
        $statement->execute(['cutoff' => $this->clock->now()->modify('-1 day')->format(self::SQL_DATETIME)]);

        return $statement->rowCount();
    }

    /**
     * @return array{bucket: string, subject: string, window_start: string}
     */
    private function key(string $bucket, string $subject, int $windowStart): array
    {
        return [
            'bucket' => $bucket,
            'subject' => hash_hmac('sha256', $subject, $this->hashKey),
            'window_start' => gmdate(self::SQL_DATETIME, $windowStart),
        ];
    }
}
