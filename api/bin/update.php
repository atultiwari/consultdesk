<?php

declare(strict_types=1);

// In-app updates from the command line (the same as admin → System → Updates).
//   php bin/update.php check          is a newer version out?
//   php bin/update.php apply [--yes]  back up, download, verify the signature, install, run database updates
// Needs PUBLIC_PATH (the web folder) in config.php; the installer writes it.

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Infra\Config;
use ConsultDesk\Updates\UpdateFailed;
use ConsultDesk\Version;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = is_array($_SERVER['argv'] ?? null) ? array_map('strval', array_slice($_SERVER['argv'], 1)) : [];
$command = $args[0] ?? 'check';

try {
    $services = new AppServices(Config::load(dirname(__DIR__) . '/config.php', getenv()));
    $status = $services->updateChecker()->check();
    if ($status['error'] !== null) {
        throw new UpdateFailed((string) $status['error']);
    }
    $latest = $status['latest'];
    echo 'Installed: ' . Version::CURRENT . "\n";
    echo 'Latest:    ' . (is_array($latest) ? $latest['version'] : 'none published') . "\n";
    if (!$status['available'] || !is_array($latest)) {
        echo "Up to date.\n";
        exit(0);
    }
    if ($command !== 'apply') {
        echo "Run `php bin/update.php apply` to install it.\n";
        exit(0);
    }
    $updater = $services->updater();
    $blocker = $updater->blocker();
    if ($blocker !== null) {
        throw new UpdateFailed($blocker . ' (Set PUBLIC_PATH in config.php to the web folder.)');
    }
    if (!in_array('--yes', $args, true)) {
        fwrite(STDOUT, "Install {$latest['version']} now? A backup is taken first. Type UPDATE to continue: ");
        if (trim((string) fgets(STDIN)) !== 'UPDATE') {
            echo "Nothing changed.\n";
            exit(1);
        }
    }
    $release = $services->updateChecker()->release() ?? throw new UpdateFailed('No release found.');
    $result = $updater->apply($release);
    printf("Updated %s → %s. Backup: storage/backups/%s. Database updates: %s.\n", $result['from'], $result['to'], $result['backup'], $result['migrations'] === [] ? 'none' : implode(', ', $result['migrations']));
} catch (UpdateFailed $e) {
    fwrite(STDERR, 'Not updated: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Update failed: ' . $e->getMessage() . "\n");
    exit(1);
}
