<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Applies `NNN_name.sql` files from a directory in order, recording each in `migrations`.
 *
 * MySQL commits implicitly on DDL, so a migration is not atomic: keep each file small and re-runnable
 * where practical. A file is recorded only after all of its statements succeed.
 */
final class Migrator
{
    private const FILE_PATTERN = '/^\d{3}_[a-z0-9_]+\.sql$/';
    private const LOCK_NAME = 'consultdesk_migrate';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
        private readonly Clock $clock,
        private readonly int $lockTimeoutSeconds = 10,
    ) {}

    /**
     * @return list<string> versions not yet applied, in order
     */
    public function pending(): array
    {
        $this->ensureTable();
        $statement = $this->pdo->prepare('SELECT version FROM migrations');
        $statement->execute();
        $applied = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_diff($this->available(), $applied));
    }

    /**
     * @return list<string> versions applied by this call
     */
    public function migrate(): array
    {
        $this->acquireLock();
        try {
            $applied = [];
            foreach ($this->pending() as $version) {
                $this->apply($version);
                $applied[] = $version;
            }

            return $applied;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => self::LOCK_NAME]);
            $release->closeCursor();
        }
    }

    /**
     * A named server lock stops two runs (e.g. a double-clicked "Run database updates") overlapping.
     */
    private function acquireLock(): void
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, :timeout)');
        $statement->execute(['name' => self::LOCK_NAME, 'timeout' => $this->lockTimeoutSeconds]);
        $acquired = (int) $statement->fetchColumn();
        $statement->closeCursor();
        if ($acquired !== 1) {
            throw new RuntimeException('Another database update is already running. Try again in a moment.');
        }
    }

    private function apply(string $version): void
    {
        $path = $this->directory . '/' . $version . '.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException(sprintf('Cannot read migration %s.', $path));
        }

        foreach (SqlSplitter::split($sql) as $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (PDOException $e) {
                throw new RuntimeException(sprintf('Migration %s failed: %s', $version, $e->getMessage()), 0, $e);
            }
        }

        $this->pdo
            ->prepare('INSERT INTO migrations (version, applied_at) VALUES (?, ?)')
            ->execute([$version, $this->clock->now()->format('Y-m-d H:i:s')]);
    }

    /**
     * @return list<string>
     */
    public function available(): array
    {
        $files = is_dir($this->directory) ? scandir($this->directory) : false;
        if ($files === false) {
            throw new RuntimeException(sprintf('Cannot read migrations directory %s.', $this->directory));
        }

        $versions = [];
        foreach ($files as $file) {
            if (preg_match(self::FILE_PATTERN, $file) === 1) {
                $versions[] = substr($file, 0, -4);
            }
        }
        sort($versions, SORT_STRING);

        return $versions;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }
}
