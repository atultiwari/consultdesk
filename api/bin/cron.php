<?php

declare(strict_types=1);

// Scheduled task. On Hostinger: hPanel → Advanced → Cron Jobs, every minute:
//   php /home/<user>/domains/<domain>/public_html/<path>/api/bin/cron.php
// Prints a JSON summary; exits 1 on failure.

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Infra\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $services = new AppServices(Config::load(dirname(__DIR__) . '/config.php', getenv()));
    echo json_encode($services->cronRunner()->run()->toArray(), JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Cron failed: ' . $e->getMessage() . "\n");
    exit(1);
}
