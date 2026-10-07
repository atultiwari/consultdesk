<?php

declare(strict_types=1);

// Sets up (or updates) the database and the first owner. With OWNER_EMAIL and OWNER_PASSWORD in
// .env the owner is created here; otherwise open the admin path once to create it, like WordPress.
//
//   php bin/install.php           apply database updates, create the owner from .env if there is none
//   php bin/install.php --fresh   LOCAL ONLY: back up, then empty the database and start again
//                                 (add --yes to skip the question)

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Infra\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = is_array($_SERVER['argv'] ?? null) ? array_map('strval', array_slice($_SERVER['argv'], 1)) : [];

try {
    $config = Config::load(dirname(__DIR__) . '/config.php', getenv());
    $services = new AppServices($config);
    $pdo = $services->db()->pdo();

    if (in_array('--fresh', $args, true)) {
        $host = strtolower((string) parse_url($config->appUrl, PHP_URL_HOST));
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
        if (!$local) {
            throw new RuntimeException('--fresh only works on a local machine (APP_URL on localhost). Use bin/restore.php on a real site.');
        }
        if (!in_array('--yes', $args, true)) {
            fwrite(STDOUT, 'This empties the whole database (a backup is saved first). Type RESET to continue: ');
            if (trim((string) fgets(STDIN)) !== 'RESET') {
                fwrite(STDOUT, "Nothing changed.\n");
                exit(1);
            }
        }
        $list = $pdo->prepare("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $list->execute();
        $tables = $list->fetchAll(PDO::FETCH_COLUMN);
        if ($tables !== []) {
            echo 'Backup saved to ' . $services->backup()->create('before-reset') . "\n";
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        echo "Database emptied.\n";
    }

    $applied = $services->migrator()->migrate();
    echo $applied === [] ? "Database is up to date.\n" : 'Database updates applied: ' . implode(', ', $applied) . "\n";

    $firstRun = $services->firstRun();
    $created = $firstRun->createFromEnv();
    $admin = $config->adminPath === null ? null : $config->appUrl . '/' . $config->adminPath;
    if ($created !== null) {
        echo "Owner account created from .env ({$config->owner?->email}). Remove OWNER_PASSWORD from .env once you've signed in.\n";
    } elseif ($firstRun->needed()) {
        echo $admin === null
            ? "No accounts yet. Set ADMIN_PATH, then open the admin area to create the owner.\n"
            : "No accounts yet. Open {$admin} to create the owner account.\n";
        if (str_starts_with($config->appUrl, 'https://') && $config->setupKey === null) {
            echo "Tip: this site is public. Set SETUP_KEY so only you can create the owner, or do it right away.\n";
        }
    }
    if ($admin !== null) {
        echo "Admin area: {$admin}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
