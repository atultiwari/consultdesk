<?php

declare(strict_types=1);

// Telegram bot setup.
//   php bin/telegram.php info                    bot name and webhook status
//   php bin/telegram.php set-webhook             point the bot at <APP_URL>/api/webhooks/telegram
//   php bin/telegram.php link provider <slug>    one-time link for a provider's chat
//   php bin/telegram.php link user <email>       one-time link for an owner/admin/provider user's chat
//   php bin/telegram.php poll                    local development only: fetch updates from Telegram
//                                                instead of the webhook, and run cron every few seconds.
//                                                Removes the webhook; run set-webhook again on a live site.
//                                                Restart it after connecting or changing the bot in the admin area.
// Links work once: 24 hours for providers, 15 minutes for owner/admin users. (The admin panel will offer the same in Phase 6.)

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
    $config = $services->telegramConfig() ?? $fail('Telegram is not configured: connect a bot in the admin area (Integrations), or set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET.');
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
            echo $type === 'provider'
                ? "Open this link on the phone that should get alerts (valid 24 hours, once):\n"
                : "Open this link now, in a private chat on your own phone (valid 15 minutes, once):\n";
            echo "https://t.me/{$username}?start={$code}\n";
            break;

        case 'poll':
            $bot = $services->telegramBot() ?? $fail('Telegram is not configured.');
            $poller = new ConsultDesk\Telegram\TelegramPoller($telegram->api, static fn(array $update) => $bot->handle($update));
            $poller->takeOverFromWebhook();
            $me = $telegram->api->call('getMe');
            printf("Listening as @%s. Message the bot or tap its buttons; press Ctrl+C to stop.\n", (string) ($me['username'] ?? '?'));
            $offset = 0;
            // @phpstan-ignore while.alwaysTrue (runs until Ctrl+C)
            while (true) {
                $offset = $poller->pollOnce($offset);
                // Alerts go out through the outbox, so keep cron going too.
                $report = $services->cronRunner()->run(3.0);
                if ($report->ran && ($report->jobsSucceeded + $report->jobsFailed) > 0) {
                    printf("[%s] sent %d, failed %d\n", date('H:i:s'), $report->jobsSucceeded, $report->jobsFailed);
                }
                sleep(2);
            }

            // no break
        default:
            $fail('Usage: php bin/telegram.php info | set-webhook | link provider <slug> | link user <email> | poll');
    }
} catch (Throwable $e) {
    $fail('Telegram command failed: ' . $e->getMessage());
}
