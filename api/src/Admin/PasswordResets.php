<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Crypto;
use ConsultDesk\Infra\Db;
use ConsultDesk\Notify\Outbox;
use PDO;

/**
 * "Forgot password": a one-time link valid for 30 minutes, emailed through the outbox. Asking for a
 * link never reveals whether the email belongs to anyone. Using it signs the user out everywhere.
 */
final class PasswordResets
{
    public const EMAIL_JOB = 'email.password_reset';
    private const TTL_MINUTES = 30;
    private const SQL = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Db $db,
        private readonly AdminUsers $users,
        private readonly Passwords $passwords,
        private readonly Sessions $sessions,
        private readonly Outbox $outbox,
        private readonly Crypto $crypto,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {}

    public function request(string $email): void
    {
        $found = $this->users->findForLogin($email);
        if ($found === null) {
            return;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $this->db->transaction(function (PDO $pdo) use ($found, $token, $now): void {
            $pdo->prepare(
                'INSERT INTO password_resets (user_id, token_hash, token_enc, expires_at, created_at) VALUES (:user, :hash, :enc, :expires, :created)',
            )->execute([
                'user' => $found[0]->id,
                'hash' => hash('sha256', $token),
                'enc' => $this->crypto->encrypt($token),
                'expires' => $now->modify(sprintf('+%d minutes', self::TTL_MINUTES))->format(self::SQL),
                'created' => $now->format(self::SQL),
            ]);
            $this->outbox->enqueue(self::EMAIL_JOB, ['reset_id' => (int) $pdo->lastInsertId()]);
        });
    }

    /**
     * @throws AuthFailed
     */
    public function reset(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): void
    {
        $userId = $this->db->transaction(function (PDO $pdo) use ($token, $password): int {
            $statement = $pdo->prepare(
                'SELECT id, user_id FROM password_resets WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now FOR UPDATE',
            );
            $statement->execute(['hash' => hash('sha256', $token), 'now' => $this->clock->now()->format(self::SQL)]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw AuthFailed::invalidResetLink();
            }

            $pdo->prepare('UPDATE password_resets SET used_at = :now, token_enc = NULL WHERE id = :id')
                ->execute(['now' => $this->clock->now()->format(self::SQL), 'id' => $row['id']]);
            $this->users->setPasswordHash((int) $row['user_id'], $this->passwords->hash($password));

            return (int) $row['user_id'];
        });

        $this->sessions->endAll($userId);
        $this->audit->record(Actor::user($userId), 'admin.password_reset', 'user', $userId);
    }
}
