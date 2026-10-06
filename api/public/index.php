<?php

declare(strict_types=1);

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Http\AppFactory;
use ConsultDesk\Infra\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config = Config::load(dirname(__DIR__) . '/config.php', getenv());
} catch (Throwable $e) {
    error_log('[consultdesk] configuration error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'data' => null, 'error' => ['code' => 'not_configured', 'message' => 'This site is not configured yet.'], 'meta' => null]);
    exit;
}

AppFactory::create(new AppServices($config))->run();
