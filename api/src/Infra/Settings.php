<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use PDO;

/**
 * Key/value JSON settings (the settings table): site branding, cron health and the like.
 */
final class Settings
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<string, mixed> empty when the key is not set
     */
    public function get(string $key): array
    {
        $statement = $this->pdo->prepare('SELECT `value` FROM settings WHERE `key` = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        $decoded = is_string($value) ? json_decode($value, true, 8) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    public function put(string $key, array $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        )->execute(['key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
}
