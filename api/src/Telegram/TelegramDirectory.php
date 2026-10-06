<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\ActorType;
use PDO;

/**
 * Which chats get alerts and which chats may act on a provider's bookings.
 *
 * - Alerts go to the provider's chat, or to the owners' chats when the provider has none.
 * - A chat may act if it is the provider's chat, the chat of that provider's own user,
 *   or the chat of an owner or admin.
 */
final class TelegramDirectory
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return list<string>
     */
    public function alertChats(int $providerId): array
    {
        $provider = $this->column('SELECT telegram_chat_id FROM providers WHERE id = :id AND telegram_chat_id IS NOT NULL', ['id' => $providerId]);
        if ($provider !== []) {
            return $provider;
        }

        return array_values(array_unique($this->column(
            "SELECT telegram_chat_id FROM users WHERE role = 'owner' AND telegram_chat_id IS NOT NULL AND disabled_at IS NULL ORDER BY id",
            [],
        )));
    }

    /**
     * The actor for a button press, or null if this chat may not act on the provider's bookings.
     */
    public function actorFor(string $chatId, int $providerId): ?Actor
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM users
             WHERE telegram_chat_id = :chat AND disabled_at IS NULL AND (role IN ('owner', 'admin') OR (role = 'provider' AND provider_id = :provider))
             ORDER BY FIELD(role, 'owner', 'admin', 'provider'), id LIMIT 1",
        );
        $statement->execute(['chat' => $chatId, 'provider' => $providerId]);
        $userId = $statement->fetchColumn();
        if ($userId !== false) {
            return new Actor(ActorType::Telegram, (int) $userId);
        }

        $isProviderChat = $this->column('SELECT id FROM providers WHERE id = :id AND telegram_chat_id = :chat', ['id' => $providerId, 'chat' => $chatId]) !== [];

        return $isProviderChat ? new Actor(ActorType::Telegram) : null;
    }

    /**
     * Removes this chat from every provider and user. Returns how many links were removed.
     */
    public function unlink(string $chatId): int
    {
        $removed = 0;
        foreach (['providers', 'users'] as $table) {
            $statement = $this->pdo->prepare("UPDATE {$table} SET telegram_chat_id = NULL WHERE telegram_chat_id = :chat");
            $statement->execute(['chat' => $chatId]);
            $removed += $statement->rowCount();
        }

        return $removed;
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return list<string>
     */
    private function column(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }
}
