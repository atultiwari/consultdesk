<?php

declare(strict_types=1);

// Writes a signed backup of the whole database (gzipped SQL) into storage/backups, or --out=FILE.
// Restore it with bin/restore.php or from the admin panel (System → Backups), on a site with the
// same APP_KEY.
// Usage: php bin/backup.php [--out=FILE]

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Infra\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

try {
    $services = new AppServices(Config::load(dirname(__DIR__) . '/config.php', getenv()));
    $path = $services->backup()->create('backup');
    $out = getopt('', ['out:'])['out'] ?? null;
    if (is_string($out) && $out !== '') {
        if (!rename($path, $out)) {
            throw new RuntimeException("Could not move the backup to {$out}.");
        }
        $path = $out;
    }
    echo "Backup written to {$path}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
