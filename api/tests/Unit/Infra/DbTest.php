<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Infra;

use ConsultDesk\Infra\Db;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DbTest extends TestCase
{
    private PDO $pdo;
    private Db $db;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE t (v INTEGER)');
        $this->db = new Db($this->pdo);
    }

    public function testCommitsOnSuccessAndReturnsTheResult(): void
    {
        $result = $this->db->transaction(function (PDO $pdo): string {
            $pdo->exec('INSERT INTO t VALUES (1)');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $this->rows());
    }

    public function testRollsBackAndRethrowsOnFailure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        try {
            $this->db->transaction(function (PDO $pdo): void {
                $pdo->exec('INSERT INTO t VALUES (1)');
                throw new RuntimeException('boom');
            });
        } finally {
            self::assertSame(0, $this->rows());
        }
    }

    public function testRetriesDeadlocksAndLockTimeouts(): void
    {
        $attempts = 0;
        $this->db->transaction(function (PDO $pdo) use (&$attempts): void {
            $attempts++;
            $pdo->exec('INSERT INTO t VALUES (1)');
            if ($attempts === 1) {
                throw self::mysqlError(1213, 'Deadlock found');
            }
            if ($attempts === 2) {
                throw self::mysqlError(1205, 'Lock wait timeout exceeded');
            }
        });

        self::assertSame(3, $attempts);
        self::assertSame(1, $this->rows(), 'failed attempts are rolled back');
    }

    public function testGivesUpAfterTheLastRetry(): void
    {
        $attempts = 0;
        $this->expectException(PDOException::class);
        try {
            $this->db->transaction(function () use (&$attempts): void {
                $attempts++;
                throw self::mysqlError(1213, 'Deadlock found');
            });
        } finally {
            self::assertSame(Db::MAX_ATTEMPTS, $attempts);
        }
    }

    public function testDoesNotRetryOtherDatabaseErrors(): void
    {
        $attempts = 0;
        $this->expectException(PDOException::class);
        try {
            $this->db->transaction(function () use (&$attempts): void {
                $attempts++;
                throw self::mysqlError(1062, 'Duplicate entry');
            });
        } finally {
            self::assertSame(1, $attempts);
        }
    }

    private function rows(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM t');
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private static function mysqlError(int $code, string $message): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = ['40001', $code, $message];

        return $e;
    }
}
