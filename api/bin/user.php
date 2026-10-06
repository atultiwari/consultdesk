<?php

declare(strict_types=1);

// Manages admin accounts from the server's shell: create the first owner, reset a forgotten
// password, list accounts. Reads config.php or the environment, like bin/cron.php.
// Usage: php bin/user.php create --email=EMAIL --role=owner|admin|provider [--provider=SLUG] [--name=NAME]
//        php bin/user.php reset-password --email=EMAIL
//        php bin/user.php list

use ConsultDesk\Admin\Passwords;
use ConsultDesk\Admin\UserCommand;
use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Infra\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** Reads a line from the terminal without echoing it (falls back to plain input when not a TTY). */
$tty = stream_isatty(STDIN);
$askPassword = static function (string $prompt) use ($tty): string {
    fwrite(STDOUT, $prompt);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return $line === false ? '' : rtrim($line, "\r\n");
};

try {
    $services = new AppServices(Config::load(dirname(__DIR__) . '/config.php', getenv()));
    $pdo = $services->db()->pdo();
    $command = new UserCommand(
        $pdo,
        $services->adminUsers(),
        new Passwords(),
        $services->sessions(),
        $services->auditLog(),
        $askPassword,
        static function (string $text): void {
            fwrite(STDOUT, $text);
        },
    );
    $args = is_array($_SERVER['argv'] ?? null) ? array_values(array_slice($_SERVER['argv'], 1)) : [];
    exit($command->run(array_map('strval', $args)));
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}
