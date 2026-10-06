<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration;

use ConsultDesk\Infra\Db;
use ConsultDesk\Infra\DbConfig;
use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Infra\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real MySQL/MariaDB test database named by DB_TEST_NAME.
 * The schema is rebuilt from migrations once per run; every test starts with empty tables.
 * Tests are skipped when the database env vars are absent (e.g. a quick host-only run).
 */
abstract class IntegrationTestCase extends TestCase
{
    public const MIGRATIONS_DIR = __DIR__ . '/../../migrations';

    private static ?Db $db = null;
    private static bool $schemaReady = false;

    protected Db $database;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->database = self::db();
        $this->pdo = $this->database->pdo();

        if (!self::$schemaReady) {
            self::dropAllTables($this->pdo);
            (new Migrator($this->pdo, self::MIGRATIONS_DIR, new FrozenClock('2026-01-01T00:00Z')))->migrate();
            self::$schemaReady = true;
        }
        self::truncateDataTables($this->pdo);
    }

    public static function config(): ?DbConfig
    {
        $env = getenv();
        if (($env['DB_HOST'] ?? '') === '' || ($env['DB_TEST_NAME'] ?? '') === '') {
            return null;
        }

        return DbConfig::fromEnv($env, 'DB_TEST_NAME');
    }

    protected static function db(): Db
    {
        if (self::$db === null) {
            $config = self::config();
            if ($config === null) {
                self::markTestSkipped('Set DB_HOST, DB_TEST_NAME, DB_USER and DB_PASSWORD to run integration tests.');
            }
            self::$db = Db::connect($config);
        }

        return self::$db;
    }

    /**
     * Forces the next test to rebuild the schema (for tests that drop or migrate tables themselves).
     */
    protected static function invalidateSchema(): void
    {
        self::$schemaReady = false;
    }

    public static function dropAllTables(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::column($pdo, 'SHOW TABLES') as $table) {
            $pdo->exec(sprintf('DROP TABLE `%s`', str_replace('`', '``', $table)));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return list<string> the first column of every row
     */
    public static function column(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private static function truncateDataTables(PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::column($pdo, 'SHOW TABLES') as $table) {
            if ($table !== 'migrations') {
                $pdo->exec(sprintf('TRUNCATE TABLE `%s`', str_replace('`', '``', $table)));
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
