<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use PDO;
use Throwable;

/**
 * Thin PDO wrapper: exceptions on error, real prepared statements, utf8mb4 and a UTC session.
 */
final class Db
{
    public function __construct(private readonly PDO $pdo) {}

    public static function connect(DbConfig $config): self
    {
        $pdo = new PDO($config->dsn(), $config->user, $config->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'");

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Runs $work inside a transaction, committing on success and rolling back on any exception.
     *
     * @template T
     *
     * @param callable(PDO): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this->pdo);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
