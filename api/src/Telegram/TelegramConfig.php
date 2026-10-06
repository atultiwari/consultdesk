<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use InvalidArgumentException;

final class TelegramConfig
{
    private const TOKEN_PATTERN = '/^\d{5,}:[A-Za-z0-9_-]{30,}$/';
    /** Telegram allows 1–256 of these characters in secret_token; we insist on 32+. */
    private const SECRET_PATTERN = '/^[A-Za-z0-9_-]{32,256}$/';
    private const USERNAME_PATTERN = '/^[A-Za-z0-9_]{5,32}$/';

    public function __construct(
        #[\SensitiveParameter]
        public readonly string $botToken,
        #[\SensitiveParameter]
        public readonly string $webhookSecret,
        public readonly ?string $botUsername,
    ) {
        if (preg_match(self::TOKEN_PATTERN, $botToken) !== 1) {
            throw new InvalidArgumentException('TELEGRAM_BOT_TOKEN does not look like a token from @BotFather.');
        }
        if (preg_match(self::SECRET_PATTERN, $webhookSecret) !== 1) {
            throw new InvalidArgumentException('TELEGRAM_WEBHOOK_SECRET must be 32–256 letters, digits, "_" or "-".');
        }
        if ($botUsername !== null && preg_match(self::USERNAME_PATTERN, $botUsername) !== 1) {
            throw new InvalidArgumentException('TELEGRAM_BOT_USERNAME is not a valid bot username.');
        }
    }

    /**
     * Null when Telegram is not configured (no bot token).
     *
     * @param array<string, string> $values
     */
    public static function fromValues(array $values): ?self
    {
        $token = trim($values['TELEGRAM_BOT_TOKEN'] ?? '');
        if ($token === '') {
            return null;
        }
        $username = ltrim(trim($values['TELEGRAM_BOT_USERNAME'] ?? ''), '@');

        return new self($token, trim($values['TELEGRAM_WEBHOOK_SECRET'] ?? ''), $username === '' ? null : $username);
    }
}
