<?php

declare(strict_types=1);

// Telegram bot setup.
//   php bin/telegram.php info                    bot name and webhook status
//   php bin/telegram.php set-webhook             point the bot at <APP_URL>/api/webhooks/telegram
//   php bin/telegram.php link provider <slug>    one-time link for a provider's chat
//   php bin/telegram.php link user <email>       one-time link for an owner/admin/provider user's chat
// Links expire after 24 hours and work once. (The admin panel will offer the same in Phase 6.)

use ConsultDesk\Bootstrap\AppServices;
use ConsultDesk\Infra\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$args = array_values(array_map('strval', is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));
$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

try {
    $services = new AppServices(Config::load(dirname(__DIR__) . '/config.php', getenv()));
    $config = $services->config->telegram ?? $fail('Telegram is not configured: set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET.');
    $telegram = $services->telegramServices() ?? $fail('Telegram is not configured.');

    switch ($args[1] ?? '') {
        case 'info':
            $me = $telegram->api->call('getMe');
            $hook = $telegram->api->call('getWebhookInfo');
            printf("Bot: @%s\n", (string) ($me['username'] ?? '?'));
            printf("Webhook: %s\n", ($hook['url'] ?? '') === '' ? '(not set)' : (string) $hook['url']);
            printf("Pending updates: %d\n", (int) ($hook['pending_update_count'] ?? 0));
            if (is_string($hook['last_error_message'] ?? null)) {
                printf("Last error: %s\n", $hook['last_error_message']);
            }
            break;

        case 'set-webhook':
            $url = $services->config->appUrl . '/api/webhooks/telegram';
            if (!str_starts_with($url, 'https://')) {
                $fail('Telegram only delivers webhooks to https URLs; APP_URL is ' . $services->config->appUrl);
            }
            $telegram->api->setWebhook($url, $config->webhookSecret);
            echo "Webhook set to {$url}\n";
            break;

        case 'link':
            [$type, $who] = [$args[2] ?? '', $args[3] ?? ''];
            $pdo = $services->db()->pdo();
            $lookup = match ($type) {
                'provider' => $pdo->prepare('SELECT id FROM providers WHERE slug = ?'),
                'user' => $pdo->prepare('SELECT id FROM users WHERE email = ?'),
                default => $fail('Usage: link provider <slug> | link user <email>'),
            };
            $lookup->execute([$who]);
            $id = $lookup->fetchColumn();
            if ($id === false) {
                $fail("No {$type} found for \"{$who}\".");
            }
            $username = $config->botUsername ?? (string) ($telegram->api->call('getMe')['username'] ?? '');
            $code = $services->linkCodes()->create($type, (int) $id);
            echo "Open this link on the phone that should get alerts (valid 24 hours, once):\n";
            echo "https://t.me/{$username}?start={$code}\n";
            break;

        default:
            $fail('Usage: php bin/telegram.php info | set-webhook | link provider <slug> | link user <email>');
    }
} catch (Throwable $e) {
    $fail('Telegram command failed: ' . $e->getMessage());
}
