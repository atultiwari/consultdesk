<?php

declare(strict_types=1);

// Replaces the whole database with a backup made by this site (same APP_KEY). A safety backup of
// the current database is written to storage/backups first. Everyone is signed out.
// Usage: php bin/restore.php FILE [--yes]

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Infra\Config;
use ConsultDesk\Infra\InvalidBackup;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = is_array($_SERVER['argv'] ?? null) ? array_slice($_SERVER['argv'], 1) : [];
$file = null;
foreach ($args as $arg) {
    if (!str_starts_with((string) $arg, '--')) {
        $file = (string) $arg;
    }
}
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/restore.php FILE [--yes]\n");
    exit(1);
}

try {
    $services = new AppServices(Config::load(dirname(__DIR__) . '/config.php', getenv()));
    $backup = $services->backup();
    $backup->verify($file);
    if (!in_array('--yes', $args, true)) {
        fwrite(STDOUT, "This replaces everything in the database with {$file}. Type RESTORE to continue: ");
        if (trim((string) fgets(STDIN)) !== 'RESTORE') {
            fwrite(STDOUT, "Nothing changed.\n");
            exit(1);
        }
    }
    $safety = $backup->restore($file);
    echo "Restored. The previous database was saved to {$safety}\n";
} catch (InvalidBackup $e) {
    fwrite(STDERR, 'Not restored: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore failed: ' . $e->getMessage() . "\n");
    exit(1);
}
