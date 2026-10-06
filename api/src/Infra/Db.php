<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use PDO;
use PDOException;
use Throwable;

/**
 * Thin PDO wrapper: exceptions on error, real prepared statements, utf8mb4, a UTC session,
 * strict SQL mode and READ COMMITTED isolation.
 *
 * READ COMMITTED avoids InnoDB gap locks (fewer deadlocks between hold() and cron) and makes every
 * read inside a transaction see the latest committed data, so correctness never depends on taking
 * row locks before the first plain SELECT.
 */
final class Db
{
    public const MAX_ATTEMPTS = 3;
    /** MySQL/MariaDB errors after which the whole transaction can safely be retried. */
    private const RETRYABLE_ERRORS = [1205 /* lock wait timeout */, 1213 /* deadlock */];

    public function __construct(private readonly PDO $pdo) {}

    public static function connect(DbConfig $config): self
    {
        $pdo = new PDO($config->dsn(), $config->user, $config->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec(
            "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00',"
            . " sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        );
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Runs $work inside a transaction, committing on success and rolling back on any exception.
     * Deadlocks and lock-wait timeouts are retried up to MAX_ATTEMPTS times, so $work must only
     * touch the database (no emails or HTTP calls) and be safe to run again from the start.
     *
     * @template T
     *
     * @param callable(PDO): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $result = $work($this->pdo);
                $this->pdo->commit();

                return $result;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($attempt >= self::MAX_ATTEMPTS || !self::isRetryable($e)) {
                    throw $e;
                }
            }
        }
    }

    private static function isRetryable(Throwable $e): bool
    {
        return $e instanceof PDOException && in_array($e->errorInfo[1] ?? null, self::RETRYABLE_ERRORS, true);
    }
}
