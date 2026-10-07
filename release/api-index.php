<?php

declare(strict_types=1);

// ConsultDesk API entry point. The app itself (code, settings, uploads) lives in a folder named
// "consultdesk-app" outside the web root; this finds it by looking up from here. To keep it
// somewhere else, set CONSULTDESK_APP to its full path in the hosting panel or .htaccess (SetEnv).

$app = getenv('CONSULTDESK_APP') ?: '';
for ($dir = __DIR__, $i = 0; $app === '' && $i < 6; $i++) {
    $dir = dirname($dir);
    if (is_file($dir . '/consultdesk-app/http.php')) {
        $app = $dir . '/consultdesk-app';
    }
}
if ($app === '' || !is_file($app . '/http.php')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"success":false,"data":null,"error":{"code":"app_missing","message":"The consultdesk-app folder was not found next to the website folder."},"meta":null}';
    exit;
}

require $app . '/http.php';
