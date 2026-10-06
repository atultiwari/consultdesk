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
        $statement = $this->pdo->prepare('SELECT id, email, name, role, provider_id, password_hash FROM users WHERE email = :email');
        $statement->execute(['email' => strtolower(trim($email))]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [self::hydrate($row), (string) $row['password_hash']] : null;
    }

    public function find(int $id): ?AdminUser
    {
        $statement = $this->pdo->prepare('SELECT id, email, name, role, provider_id FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function setPasswordHash(int $userId, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = :now WHERE id = :id')
            ->execute(['hash' => $hash, 'now' => $this->now(), 'id' => $userId]);
    }

    public function recordLogin(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id')->execute(['now' => $this->now(), 'id' => $userId]);
    }

    public function create(string $email, string $passwordHash, Role $role, ?int $providerId, ?string $name = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (email, name, password_hash, role, provider_id, created_at, updated_at)
             VALUES (:email, :name, :hash, :role, :provider, :created, :updated)',
        )->execute([
            'email' => strtolower(trim($email)),
            'name' => $name,
            'hash' => $passwordHash,
            'role' => $role->value,
            'provider' => $providerId,
            'created' => $this->now(),
            'updated' => $this->now(),
        ]);

        return (int) $this->pdo->lastInsertId();
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
