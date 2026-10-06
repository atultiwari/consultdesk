<?php

declare(strict_types=1);

// Applies pending database migrations. Reads DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD
// from the environment (the Phase 8 installer will switch this to config.php).
// Usage: php bin/migrate.php [--status]

use ConsultDesk\Infra\Db;
use ConsultDesk\Infra\DbConfig;
use ConsultDesk\Infra\Migrator;
use ConsultDesk\Infra\SystemClock;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $db = Db::connect(DbConfig::fromEnv(getenv()));
    $migrator = new Migrator($db->pdo(), dirname(__DIR__) . '/migrations', new SystemClock());

    $args = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
    if (in_array('--status', $args, true)) {
        $pending = $migrator->pending();
        echo $pending === [] ? "Database is up to date.\n" : 'Pending: ' . implode(', ', $pending) . "\n";
        exit(0);
    }

    $applied = $migrator->migrate();
    echo $applied === [] ? "Nothing to migrate.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
