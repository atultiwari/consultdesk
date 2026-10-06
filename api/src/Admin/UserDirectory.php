<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Infra\Clock;
use PDO;

/**
 * Accounts as the owner's Users panel shows and edits them.
 *
 * Status: "active" (has a password), "invited" (waiting, with a live link), "invite_expired" or
 * "disabled" (cannot sign in; their sessions were ended).
 */
final class UserDirectory
{
    private const SQL = 'Y-m-d H:i:s';
    private const ISO = 'Y-m-d\TH:i:s\Z';
    private const SELECT = 'SELECT u.id, u.email, u.name, u.role, u.provider_id, u.password_set_at, u.last_login_at, u.disabled_at,
            (u.telegram_chat_id IS NOT NULL) AS telegram_linked, p.name AS provider_name,
            (u.invited_at > :invite_cutoff) AS invite_live
        FROM users u LEFT JOIN providers p ON p.id = u.provider_id';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' ORDER BY u.id');
        $statement->execute(['invite_cutoff' => $this->inviteCutoff()]);

        return array_values(array_map(self::present(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE u.id = :id');
        $statement->execute(['invite_cutoff' => $this->inviteCutoff(), 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::present($row) : null;
    }

    public function emailTaken(string $email): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $statement->execute(['email' => strtolower(trim($email))]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function update(int $id, ?string $name, Role $role, ?int $providerId): void
    {
        $this->pdo->prepare('UPDATE users SET name = :name, role = :role, provider_id = :provider, updated_at = :now WHERE id = :id')
            ->execute(['name' => $name, 'role' => $role->value, 'provider' => $providerId, 'now' => $this->now(), 'id' => $id]);
    }

    /**
     * Records that an invite was just sent; it stays valid for PasswordResets::INVITE_TTL_HOURS.
     */
    public function markInvited(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET invited_at = :now WHERE id = :id')->execute(['now' => $this->now(), 'id' => $id]);
    }

    /**
     * How many invites were sent to this user in the last hour (from the audit log).
     */
    public function invitesLastHour(int $id): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.user_invited' AND entity_type = 'user' AND entity_id = :id AND created_at > :since",
        );
        $statement->execute(['id' => $id, 'since' => $this->clock->now()->modify('-1 hour')->format(self::SQL)]);

        return (int) $statement->fetchColumn();
    }

    public function setDisabled(int $id, bool $disabled): void
    {
        $this->pdo->prepare('UPDATE users SET disabled_at = :at, updated_at = :now WHERE id = :id')
            ->execute(['at' => $disabled ? $this->now() : null, 'now' => $this->now(), 'id' => $id]);
    }

    private function inviteCutoff(): string
    {
        return $this->clock->now()->modify(sprintf('-%d hours', PasswordResets::INVITE_TTL_HOURS))->format(self::SQL);
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::SQL);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function present(array $r): array
    {
        $status = match (true) {
            $r['disabled_at'] !== null => 'disabled',
            $r['password_set_at'] !== null => 'active',
            (bool) $r['invite_live'] => 'invited',
            default => 'invite_expired',
        };

        return [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'name' => $r['name'],
            'role' => (string) $r['role'],
            'provider' => $r['provider_id'] === null ? null : ['id' => (int) $r['provider_id'], 'name' => (string) $r['provider_name']],
            'status' => $status,
            'last_login_at' => self::iso($r['last_login_at']),
            'telegram_linked' => (bool) $r['telegram_linked'],
        ];
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(self::ISO);
    }
}
