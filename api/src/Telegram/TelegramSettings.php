<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Infra\Crypto;
use ConsultDesk\Infra\DecryptionFailed;
use ConsultDesk\Infra\Settings;

/**
 * The Telegram bot connected from the admin area (settings key "telegram_bot"): its token and
 * webhook secret, encrypted, and its username. A bot in config.php takes precedence over this.
 */
final class TelegramSettings
{
    public const KEY = 'telegram_bot';

    public function __construct(
        private readonly Settings $settings,
        private readonly Crypto $crypto,
    ) {}

    public function load(): ?TelegramConfig
    {
        $stored = $this->settings->get(self::KEY);
        $token = $stored['token_enc'] ?? null;
        $secret = $stored['webhook_secret_enc'] ?? null;
        if (!is_string($token) || !is_string($secret)) {
            return null;
        }
        $username = $stored['username'] ?? null;
        try {
            return new TelegramConfig($this->crypto->decrypt($token), $this->crypto->decrypt($secret), is_string($username) ? $username : null);
        } catch (DecryptionFailed) {
            // E.g. APP_KEY changed: Telegram stays off (bookings carry on) until the bot is connected again.
            error_log('ConsultDesk: the saved Telegram bot can no longer be read; connect it again in Integrations.');

            return null;
        }
    }

    public function save(TelegramConfig $bot): void
    {
        $this->settings->put(self::KEY, [
            'token_enc' => $this->crypto->encrypt($bot->botToken),
            'webhook_secret_enc' => $this->crypto->encrypt($bot->webhookSecret),
            'username' => $bot->botUsername,
        ]);
    }

    public function clear(): void
    {
        $this->settings->put(self::KEY, []);
    }
}
