<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Infra\Clock;
use PDO;

final class AdminUsers
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{AdminUser, string}|null the user and their password hash
     */
    public function findForLogin(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, email, name, role, provider_id, password_hash FROM users WHERE email = :email AND disabled_at IS NULL');
        $statement->execute(['email' => strtolower(trim($email))]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [self::hydrate($row), (string) $row['password_hash']] : null;
    }

    public function find(int $id): ?AdminUser
    {
        $statement = $this->pdo->prepare('SELECT id, email, name, role, provider_id FROM users WHERE id = :id AND disabled_at IS NULL');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function setPasswordHash(int $userId, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = :hash, password_set_at = COALESCE(password_set_at, :set), updated_at = :now WHERE id = :id')
            ->execute(['hash' => $hash, 'set' => $this->now(), 'now' => $this->now(), 'id' => $userId]);
    }

    public function recordLogin(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id')->execute(['now' => $this->now(), 'id' => $userId]);
    }

    /**
     * @param string|null $passwordHash null for an invited user, who has no password until they choose one
     */
    public function create(string $email, ?string $passwordHash, Role $role, ?int $providerId, ?string $name = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash, password_set_at, role, provider_id, created_at, updated_at)
             VALUES (:email, :name, :hash, :set, :role, :provider, :created, :updated)',
        )->execute([
            'email' => strtolower(trim($email)),
            'name' => $name,
            // An invited user's hash matches no password at all.
            'hash' => $passwordHash ?? '!invited:' . bin2hex(random_bytes(16)),
            'set' => $passwordHash === null ? null : $this->now(),
            'role' => $role->value,
            'provider' => $providerId,
            'created' => $this->now(),
            'updated' => $this->now(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function hasPassword(int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT password_set_at IS NOT NULL FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);

        return (bool) $statement->fetchColumn();
    }

    public function setName(int $userId, ?string $name): void
    {
        $this->pdo->prepare('UPDATE users SET name = :name, updated_at = :now WHERE id = :id')
            ->execute(['name' => $name, 'now' => $this->now(), 'id' => $userId]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AdminUser
    {
        return new AdminUser(
            (int) $row['id'],
            (string) $row['email'],
            $row['name'] === null ? null : (string) $row['name'],
            Role::from((string) $row['role']),
            $row['provider_id'] === null ? null : (int) $row['provider_id'],
        );
    }
}
