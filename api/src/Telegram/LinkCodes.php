<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Infra\Clock;
use InvalidArgumentException;
use PDO;

/**
 * One-time codes that link a Telegram chat to a provider or user via t.me/<bot>?start=<code>.
 * Only a hash of each code is stored; codes work once and expire (24 h for providers, 15 min for owner/admin users).
 */
final class LinkCodes
{
    /** Provider links may be sent to someone else to open; owner/admin links grant approval rights. */
    private const TTL_MINUTES = ['provider' => 24 * 60, 'user' => 15];
    private const SQL_DATETIME = 'Y-m-d H:i:s';
    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @param string $targetType "provider" or "user"
     */
    public function create(string $targetType, int $targetId): string
    {
        if (!in_array($targetType, ['provider', 'user'], true)) {
            throw new InvalidArgumentException('Link codes are for providers or users.');
        }

        $code = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $this->pdo->prepare(
            'INSERT INTO telegram_link_codes (code_hash, target_type, target_id, expires_at, created_at)
             VALUES (:hash, :type, :target, :expires, :created)',
        )->execute([
            'hash' => hash('sha256', $code),
            'type' => $targetType,
            'target' => $targetId,
            'expires' => $now->modify(sprintf('+%d minutes', self::TTL_MINUTES[$targetType]))->format(self::SQL_DATETIME),
            'created' => $now->format(self::SQL_DATETIME),
        ]);

        return $code;
    }

    /**
     * Links the chat and returns a label for the linked provider or user, or null if the code is
     * unknown, expired or already used. Must run inside a transaction.
     */
    public function redeem(string $code, string $chatId): ?string
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return null;
        }
        $now = $this->clock->now()->format(self::SQL_DATETIME);
        $statement = $this->pdo->prepare(
            'SELECT target_type, target_id FROM telegram_link_codes
             WHERE code_hash = :hash AND used_at IS NULL AND expires_at > :now FOR UPDATE',
        );
        $statement->execute(['hash' => hash('sha256', $code), 'now' => $now]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $isProvider = $row['target_type'] === 'provider';
        $label = $this->pdo->prepare($isProvider ? 'SELECT name FROM providers WHERE id = :id' : 'SELECT email FROM users WHERE id = :id AND disabled_at IS NULL');
        $label->execute(['id' => $row['target_id']]);
        $name = $label->fetchColumn();
        if (!is_string($name)) {
            return null; // the provider or user was deleted, or the user disabled, after the link was made
        }

        $this->pdo->prepare('UPDATE telegram_link_codes SET used_at = :now WHERE code_hash = :hash')
            ->execute(['now' => $now, 'hash' => hash('sha256', $code)]);
        $table = $isProvider ? 'providers' : 'users';
        $this->pdo->prepare("UPDATE {$table} SET telegram_chat_id = :chat WHERE id = :id")
            ->execute(['chat' => $chatId, 'id' => $row['target_id']]);

        return $name;
    }
}
