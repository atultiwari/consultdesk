<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use ConsultDesk\Version;
use PDO;
use RuntimeException;

/**
 * Whole-database backups as gzipped SQL, one statement per line, signed with a key derived from
 * APP_KEY. Only a backup made by this site (same APP_KEY) can be restored, so an upload can never
 * run arbitrary SQL, and saved secrets in it stay encrypted with that key.
 *
 * Short-lived data (sign-in sessions, rate limits, caches) is left out; its tables are recreated empty.
 */
final class Backup
{
    public const FORMAT = 'consultdesk-backup 1';
    private const SIGNATURE_PREFIX = '-- signature: ';
    private const SKIP_DATA = ['sessions', 'rate_limits', 'login_attempts', 'google_busy_cache', 'google_oauth_states', 'telegram_link_codes'];
    private const ROWS_PER_INSERT = 200;
    private const PAGE = 1000;
    private const SAFETY_COPIES = 5;

    public function __construct(
        private readonly PDO $pdo,
        #[\SensitiveParameter]
        private readonly string $appKey,
        private readonly Migrator $migrator,
        private readonly Clock $clock,
        private readonly string $directory,
    ) {}

    /**
     * Writes a backup into the backups folder.
     *
     * @return string the file's path
     */
    public function create(string $label = 'backup'): string
    {
        $this->ensureDirectory();
        $path = sprintf('%s/consultdesk-%s-%s.sql.gz', $this->directory, $label, $this->clock->now()->format('Ymd-His'));
        $gz = gzopen($path, 'wb6') ?: throw new RuntimeException('Could not write the backup file.');
        $hmac = hash_init('sha256', HASH_HMAC, $this->signingKey());
        $write = static function (string $line) use ($gz, $hmac): void {
            hash_update($hmac, $line . "\n");
            gzwrite($gz, $line . "\n");
        };
        try {
            $write('-- ' . self::FORMAT);
            $write('-- version: ' . Version::CURRENT);
            $write('-- schema: ' . $this->latestApplied());
            $write('-- created: ' . $this->clock->now()->format(DATE_ATOM));
            $write('SET FOREIGN_KEY_CHECKS = 0;');
            foreach ($this->tables() as $table) {
                $this->dumpTable($table, $write);
            }
            $write('SET FOREIGN_KEY_CHECKS = 1;');
            gzwrite($gz, self::SIGNATURE_PREFIX . hash_final($hmac) . "\n");
        } finally {
            gzclose($gz);
        }
        chmod($path, 0o600);

        return $path;
    }

    /**
     * Replaces the whole database with the backup at $path, after saving a safety backup of the
     * current one. Brings the schema up to date afterwards if the backup is from an older version.
     *
     * @return string the safety backup's path
     *
     * @throws InvalidBackup
     */
    public function restore(string $path): string
    {
        $this->verify($path);
        $safety = $this->create('before-restore');
        $this->keepNewest('before-restore', self::SAFETY_COPIES);
        $gz = gzopen($path, 'rb') ?: throw new InvalidBackup('The backup file could not be read.');
        try {
            // Tables newer than the backup go too, so the migrations below can recreate them cleanly.
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($this->tables() as $table) {
                $this->pdo->exec('DROP TABLE ' . self::identifier($table));
            }
            while (($line = gzgets($gz)) !== false) {
                $line = rtrim($line, "\n");
                if ($line !== '' && !str_starts_with($line, '--')) {
                    $this->pdo->exec($line);
                }
            }
        } finally {
            gzclose($gz);
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->migrator->migrate();

        return $safety;
    }

    /**
     * Checks the format, the signature and that this version knows the backup's schema.
     *
     * @throws InvalidBackup
     */
    public function verify(string $path): void
    {
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            throw new InvalidBackup('The backup file could not be read.');
        }
        $hmac = hash_init('sha256', HASH_HMAC, $this->signingKey());
        $signature = null;
        $schema = null;
        $first = true;
        try {
            while (($line = gzgets($gz)) !== false) {
                if (str_starts_with($line, self::SIGNATURE_PREFIX)) {
                    $signature = trim(substr($line, strlen(self::SIGNATURE_PREFIX)));
                    break;
                }
                if ($first && rtrim($line, "\n") !== '-- ' . self::FORMAT) {
                    throw new InvalidBackup('This is not a ConsultDesk backup.');
                }
                $first = false;
                if (str_starts_with($line, '-- schema: ')) {
                    $schema = trim(substr($line, strlen('-- schema: ')));
                }
                hash_update($hmac, $line);
            }
            $trailing = gzgets($gz);
        } finally {
            gzclose($gz);
        }
        if ($signature === null || $trailing !== false || !hash_equals(hash_final($hmac), $signature)) {
            throw new InvalidBackup('This backup was changed, is incomplete, or was made by a site with a different APP_KEY.');
        }
        if ($schema !== '' && !in_array($schema, $this->migrator->available(), true)) {
            throw new InvalidBackup('This backup is from a newer version of ConsultDesk. Update this site first.');
        }
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @param callable(string): void $write
     */
    private function dumpTable(string $table, callable $write): void
    {
        $name = self::identifier($table);
        $create = $this->run('SHOW CREATE TABLE ' . $name)->fetch(PDO::FETCH_NUM);
        $write('DROP TABLE IF EXISTS ' . $name . ';');
        $write(str_replace("\n", ' ', (string) ($create[1] ?? '')) . ';');
        if (in_array($table, self::SKIP_DATA, true)) {
            return;
        }
        $order = $this->primaryKey($table);
        for ($offset = 0; ; $offset += self::PAGE) {
            $rows = $this->run(sprintf('SELECT * FROM %s%s LIMIT %d OFFSET %d', $name, $order === '' ? '' : ' ORDER BY ' . $order, self::PAGE, $offset))->fetchAll(PDO::FETCH_ASSOC);
            foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
                $columns = implode(', ', array_map(static fn(int|string $c): string => self::identifier((string) $c), array_keys($chunk[0])));
                $values = implode(', ', array_map(fn(array $row): string => '(' . implode(', ', array_map($this->literal(...), $row)) . ')', $chunk));
                $write(sprintf('INSERT INTO %s (%s) VALUES %s;', $name, $columns, $values));
            }
            if (count($rows) < self::PAGE) {
                return;
            }
        }
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $text = (string) $value;
        // Binary values (e.g. packed IP addresses) as hex, so every statement stays on one line.
        if (!mb_check_encoding($text, 'UTF-8')) {
            return $text === '' ? "''" : '0x' . bin2hex($text);
        }

        return str_replace(["\n", "\r"], ['\\n', '\\r'], (string) $this->pdo->quote($text));
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $statement = $this->run("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");

        return array_values(array_map(static fn(array $r): string => (string) $r[0], $statement->fetchAll(PDO::FETCH_NUM)));
    }

    private function primaryKey(string $table): string
    {
        $statement = $this->pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION",
        );
        $statement->execute(['t' => $table]);

        return implode(', ', array_map(static fn(mixed $c): string => self::identifier((string) $c), $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function latestApplied(): string
    {
        $latest = $this->run('SELECT MAX(version) FROM migrations')->fetchColumn();

        return is_string($latest) ? $latest : '';
    }

    private function signingKey(): string
    {
        return hash_hmac('sha256', 'consultdesk-backup-signing', $this->appKey, true);
    }

    private function keepNewest(string $label, int $keep): void
    {
        $files = glob(sprintf('%s/consultdesk-%s-*.sql.gz', $this->directory, $label)) ?: [];
        rsort($files);
        foreach (array_slice($files, $keep) as $old) {
            unlink($old);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Could not create the backups folder.');
        }
    }

    private function run(string $sql): \PDOStatement
    {
        return $this->pdo->query($sql) ?: throw new RuntimeException('Backup query failed: ' . $sql);
    }

    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
