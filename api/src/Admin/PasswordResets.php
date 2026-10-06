<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Db;
use ConsultDesk\Notify\Outbox;
use PDO;

/**
 * "Forgot password": a one-time link valid for 30 minutes.
 *
 * Asking for a link only queues a job, whether or not the email belongs to anyone, so the answer and
 * its timing reveal nothing. The job (PasswordResetEmailHandler) creates the token and emails it
 * straight away, so only its hash is stored. A new link replaces older ones, an account gets at most
 * three links an hour, and using a link signs the user out everywhere.
 */
final class PasswordResets
{
    public const EMAIL_JOB = 'email.password_reset';
    public const TTL_MINUTES = 30;
    private const MAX_PER_HOUR = 3;
    private const KEEP_HOURS = 24;
    private const SQL = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Db $db,
        private readonly AdminUsers $users,
        private readonly Passwords $passwords,
        private readonly Sessions $sessions,
        private readonly Outbox $outbox,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {}

    public function request(string $email): void
    {
        $this->outbox->enqueue(self::EMAIL_JOB, ['email' => strtolower(trim($email))]);
    }

    /**
     * Called by the email job: a fresh token for this account, or null when there is no such
     * account or it has had enough links this hour. Older unused links stop working.
     */
    public function issue(string $email): ?IssuedReset
    {
        $found = $this->users->findForLogin($email);
        if ($found === null) {
            return null;
        }
        [$user] = $found;
        $now = $this->clock->now();

        return $this->db->transaction(function (PDO $pdo) use ($user, $now): ?IssuedReset {
            $recent = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = :user AND created_at > :since FOR UPDATE');
            $recent->execute(['user' => $user->id, 'since' => $now->modify('-1 hour')->format(self::SQL)]);
            if ((int) $recent->fetchColumn() >= self::MAX_PER_HOUR) {
                return null;
            }

            $this->retireOpenLinks($pdo, $user->id);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $pdo->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (:user, :hash, :expires, :created)',
            )->execute([
                'user' => $user->id,
                'hash' => hash('sha256', $token),
                'expires' => $now->modify(sprintf('+%d minutes', self::TTL_MINUTES))->format(self::SQL),
                'created' => $now->format(self::SQL),
            ]);

            return new IssuedReset($user->email, $token);
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

            $this->retireOpenLinks($pdo, (int) $row['user_id']);
            $this->users->setPasswordHash((int) $row['user_id'], $this->passwords->hash($password));

            return (int) $row['user_id'];
        });

        $this->sessions->endAll($userId);
        $this->audit->record(Actor::user($userId), 'admin.password_reset', 'user', $userId);
    }

    /**
     * Cron: forgets links a day after they were made.
     */
    public function prune(): int
    {
        $statement = $this->db->pdo()->prepare('DELETE FROM password_resets WHERE created_at < :before');
        $statement->execute(['before' => $this->clock->now()->modify(sprintf('-%d hours', self::KEEP_HOURS))->format(self::SQL)]);

        return $statement->rowCount();
    }

    private function retireOpenLinks(PDO $pdo, int $userId): void
    {
        $pdo->prepare('UPDATE password_resets SET used_at = :now WHERE user_id = :user AND used_at IS NULL')
            ->execute(['now' => $this->clock->now()->format(self::SQL), 'user' => $userId]);
    }
}
