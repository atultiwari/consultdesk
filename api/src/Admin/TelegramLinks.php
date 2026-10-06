<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Telegram\LinkCodes;
use ConsultDesk\Telegram\TelegramServices;
use PDO;

/**
 * One-time "open in Telegram" links that tie a chat to a provider or to a user, and unlinking.
 * The same links `php bin/telegram.php link` prints.
 */
final class TelegramLinks
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?TelegramServices $telegram,
        private readonly LinkCodes $codes,
        private readonly ?string $botUsername,
    ) {}

    public function configured(): bool
    {
        return $this->telegram !== null;
    }

    /**
     * @param 'provider'|'user' $type
     *
     * @return string|null null when Telegram is not set up
     */
    public function link(string $type, int $id): ?string
    {
        if ($this->telegram === null) {
            return null;
        }
        $username = $this->botUsername ?? (string) ($this->telegram->api->call('getMe')['username'] ?? '');

        return sprintf('https://t.me/%s?start=%s', rawurlencode($username), $this->codes->create($type, $id));
    }

    public function providerLinked(int $providerId): bool
    {
        return $this->linked('SELECT telegram_chat_id FROM providers WHERE id = :id', $providerId);
    }

    public function userLinked(int $userId): bool
    {
        return $this->linked('SELECT telegram_chat_id FROM users WHERE id = :id', $userId);
    }

    public function unlinkProvider(int $providerId): void
    {
        $this->pdo->prepare('UPDATE providers SET telegram_chat_id = NULL WHERE id = :id')->execute(['id' => $providerId]);
    }

    /**
     * Unlinks the user's chat and voids any link they have not opened yet.
     */
    public function unlinkUser(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET telegram_chat_id = NULL WHERE id = :id')->execute(['id' => $userId]);
        $this->pdo->prepare("DELETE FROM telegram_link_codes WHERE target_type = 'user' AND target_id = :id AND used_at IS NULL")
            ->execute(['id' => $userId]);
    }

    private function linked(string $sql, int $id): bool
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $chat = $statement->fetchColumn();

        return is_string($chat) && $chat !== '';
    }
}
