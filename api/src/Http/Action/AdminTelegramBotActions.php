<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use Closure;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Telegram\TelegramApi;
use ConsultDesk\Telegram\TelegramApiError;
use ConsultDesk\Telegram\TelegramConfig;
use ConsultDesk\Telegram\TelegramSettings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The owner's Integrations → Telegram bot: paste the token from @BotFather and ConsultDesk checks it,
 * makes a webhook secret and points the bot's webhook here. The token is write-only. A bot set in
 * config.php is shown but managed there.
 */
final class AdminTelegramBotActions
{
    private const TOKEN = '/^\d{5,}:[A-Za-z0-9_-]{30,}$/';
    private const WEBHOOK_PATH = '/api/webhooks/telegram';

    /**
     * @param Closure(string): TelegramApi $apiFor a Bot API client for a token
     */
    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly ?TelegramConfig $fromConfig,
        private readonly Closure $apiFor,
        private readonly AuditLog $audit,
        private readonly string $appUrl,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        AdminScope::owner($request);

        return JsonResponse::success($response, $this->state());
    }

    public function save(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $this->assertNotInConfig();
        $input = new Input(JsonInput::decode($request));
        $token = $input->secret('bot_token', max: 128);
        if ($token !== null && preg_match(self::TOKEN, trim($token)) !== 1) {
            $input->reject('bot_token', 'Paste the whole token @BotFather gave you, like 123456789:AAH…');
        }
        $input->assertValid();

        $token = trim((string) $token);
        $api = ($this->apiFor)($token);
        $username = $this->botUsername($api, $input);
        $bot = new TelegramConfig($token, self::randomSecret(), $username);
        $this->connectWebhook($api, $bot);
        $previous = $this->settings->load();
        $this->settings->save($bot);
        if ($previous !== null && $previous->botToken !== $token) {
            $this->forgetWebhook($previous);
        }
        $this->audit->record(Actor::user($owner->id), 'admin.telegram_bot_saved', 'settings', null, ['bot' => $username]);

        return JsonResponse::success($response, $this->state());
    }

    /**
     * Points the bot's webhook here again, e.g. after it was used elsewhere or the site moved.
     */
    public function reconnect(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $bot = $this->current() ?? throw new ApiException(409, 'not_configured', 'Connect a Telegram bot first.');
        $this->connectWebhook(($this->apiFor)($bot->botToken), $bot);
        $this->audit->record(Actor::user($owner->id), 'admin.telegram_webhook_set', 'settings', null);

        return JsonResponse::success($response, $this->state());
    }

    public function remove(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $this->assertNotInConfig();
        $bot = $this->settings->load();
        if ($bot !== null) {
            $this->forgetWebhook($bot);
        }
        $this->settings->clear();
        $this->audit->record(Actor::user($owner->id), 'admin.telegram_bot_removed', 'settings', null);

        return JsonResponse::success($response, $this->state());
    }

    /**
     * Stops a bot we no longer use from posting here. Best effort: it may already be gone.
     */
    private function forgetWebhook(TelegramConfig $bot): void
    {
        try {
            ($this->apiFor)($bot->botToken)->call('deleteWebhook');
        } catch (TelegramApiError) {
            // Forgetting it here is what matters.
        }
    }

    private function botUsername(TelegramApi $api, Input $input): string
    {
        try {
            $me = $api->call('getMe');
        } catch (TelegramApiError $e) {
            error_log('ConsultDesk: Telegram getMe failed: ' . $e->getMessage());
            // Telegram answers 401 Unauthorized (or 404) for a wrong or revoked token; anything else is Telegram's trouble.
            if (preg_match('/Unauthorized|Not Found/i', $e->getMessage()) !== 1) {
                throw self::unreachable();
            }
            $input->reject('bot_token', 'Telegram didn’t accept this token. Copy it again from @BotFather (/mybots → your bot → API Token).');
            $input->assertValid();
            throw $e;
        }
        $username = $me['username'] ?? null;

        return is_string($username) && $username !== '' ? $username : throw self::unreachable();
    }

    /**
     * Telegram only delivers to https addresses; a local http site uses `bin/telegram.php poll`.
     */
    private function connectWebhook(TelegramApi $api, TelegramConfig $bot): void
    {
        if (!str_starts_with($this->appUrl, 'https://')) {
            return;
        }
        try {
            $api->setWebhook($this->appUrl . self::WEBHOOK_PATH, $bot->webhookSecret);
        } catch (TelegramApiError $e) {
            error_log('ConsultDesk: Telegram setWebhook failed: ' . $e->getMessage());
            if ($e->permanent) {
                throw new ApiException(422, 'webhook_rejected', sprintf('Telegram couldn’t use this site’s address (%s) for the webhook. It must be a public https address with a valid certificate.', $this->appUrl . self::WEBHOOK_PATH));
            }
            throw self::unreachable();
        }
    }

    private function assertNotInConfig(): void
    {
        if ($this->fromConfig !== null) {
            throw new ApiException(409, 'config_telegram', 'This bot is set in the server’s config.php. Change or remove it there.');
        }
    }

    private function current(): ?TelegramConfig
    {
        return $this->fromConfig ?? $this->settings->load();
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $bot = $this->current();

        return [
            'configured' => $bot !== null,
            'source' => $this->fromConfig !== null ? 'config' : ($bot !== null ? 'settings' : null),
            'bot_username' => $bot?->botUsername,
            'webhook_url' => $this->appUrl . self::WEBHOOK_PATH,
            'webhook_supported' => str_starts_with($this->appUrl, 'https://'),
        ];
    }

    private static function unreachable(): ApiException
    {
        return new ApiException(502, 'telegram_unreachable', 'Couldn’t reach Telegram just now. Please try again in a minute.');
    }

    private static function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    }
}
