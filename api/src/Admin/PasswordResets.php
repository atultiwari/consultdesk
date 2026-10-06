<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Db;
use ConsultDesk\Notify\Outbox;
use PDO;
use RuntimeException;

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
    public const INVITE_JOB = 'email.invite';
    public const TTL_MINUTES = 30;
    public const INVITE_TTL_HOURS = 48;
    /** Links made per account per hour, counted separately for resets and invites. */
    private const MAX_PER_HOUR = ['reset' => 3, 'invite' => 5];
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
     * Queues an invite email for a new user (or a fresh one when the last has expired).
     */
    public function invite(int $userId): void
    {
        $this->outbox->enqueue(self::INVITE_JOB, ['user_id' => $userId]);
    }

    /**
     * Called by the email job: a fresh token for this account, or null when there is no such
     * account or it has had enough links this hour. Older unused links stop working.
     */
    public function issue(string $email): ?IssuedReset
    {
        $found = $this->users->findForLogin($email);

        return $found === null ? null : $this->issueFor($found[0], 'reset');
    }

    /**
     * Called by the invite job: a two-day link for a user who has not chosen a password yet, or null
     * when there is nothing to send (gone, disabled, or already has a password).
     *
     * @throws RuntimeException when this user has had too many invites this hour; the job is retried later
     */
    public function issueInvite(int $userId): ?IssuedReset
    {
        $user = $this->users->find($userId);
        if ($user === null || $this->users->hasPassword($userId)) {
            return null;
        }

        return $this->issueFor($user, 'invite') ?? throw new RuntimeException('Too many invite links for this user this hour; will retry.');
    }

    /**
     * Voids every open link for a user, e.g. when their account is disabled.
     */
    public function retireAll(int $userId): void
    {
        $this->retireOpenLinks($this->db->pdo(), $userId, null);
    }

    /**
     * @param 'reset'|'invite' $purpose
     */
    private function issueFor(AdminUser $user, string $purpose): ?IssuedReset
    {
        $now = $this->clock->now();
        $expires = $purpose === 'invite'
            ? $now->modify(sprintf('+%d hours', self::INVITE_TTL_HOURS))
            : $now->modify(sprintf('+%d minutes', self::TTL_MINUTES));

        return $this->db->transaction(function (PDO $pdo) use ($user, $now, $expires, $purpose): ?IssuedReset {
            $recent = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = :user AND purpose = :purpose AND created_at > :since FOR UPDATE');
            $recent->execute(['user' => $user->id, 'purpose' => $purpose, 'since' => $now->modify('-1 hour')->format(self::SQL)]);
            if ((int) $recent->fetchColumn() >= self::MAX_PER_HOUR[$purpose]) {
                return null;
            }

            // A reset replaces older resets but leaves a live invite alone, so a stranger asking for
            // "forgot password" can't spoil someone's invitation; a new invite replaces everything.
            $this->retireOpenLinks($pdo, $user->id, $purpose === 'reset' ? 'reset' : null);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $pdo->prepare(
                'INSERT INTO password_resets (user_id, token_hash, purpose, expires_at, created_at) VALUES (:user, :hash, :purpose, :expires, :created)',
            )->execute([
                'user' => $user->id,
                'hash' => hash('sha256', $token),
                'purpose' => $purpose,
                'expires' => $expires->format(self::SQL),
                'created' => $now->format(self::SQL),
            ]);

            return new IssuedReset($user->email, $token, $user->name);
        });
    }

    /**
     * @throws AuthFailed
     */
    public function reset(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): void
    {
        $userId = $this->db->transaction(function (PDO $pdo) use ($token, $password): int {
            $statement = $pdo->prepare(
                'SELECT r.id, r.user_id FROM password_resets r JOIN users u ON u.id = r.user_id
                 WHERE r.token_hash = :hash AND r.used_at IS NULL AND r.expires_at > :now AND u.disabled_at IS NULL FOR UPDATE',
            );
            $statement->execute(['hash' => hash('sha256', $token), 'now' => $this->clock->now()->format(self::SQL)]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw AuthFailed::invalidResetLink();
            }

            $this->retireOpenLinks($pdo, (int) $row['user_id'], null);
            $this->users->setPasswordHash((int) $row['user_id'], $this->passwords->hash($password));

            return (int) $row['user_id'];
        });

        $this->sessions->endAll($userId);
        $this->audit->record(Actor::user($userId), 'admin.password_reset', 'user', $userId);
    }

    /**
     * Whether a user who has not chosen a password yet still has a live invite link.
     */
    public function hasLiveInvite(int $userId): bool
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM password_resets WHERE user_id = :user AND purpose = 'invite' AND used_at IS NULL AND expires_at > :now",
        );
        $statement->execute(['user' => $userId, 'now' => $this->clock->now()->format(self::SQL)]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Cron: forgets links a day after they expire.
     */
    public function prune(): int
    {
        $statement = $this->db->pdo()->prepare('DELETE FROM password_resets WHERE expires_at < :before');
        $statement->execute(['before' => $this->clock->now()->modify(sprintf('-%d hours', self::KEEP_HOURS))->format(self::SQL)]);

        return $statement->rowCount();
    }

    /**
     * @param 'reset'|null $purpose only links of this purpose, or null for all
     */
    private function retireOpenLinks(PDO $pdo, int $userId, ?string $purpose): void
    {
        $pdo->prepare('UPDATE password_resets SET used_at = :now WHERE user_id = :user AND used_at IS NULL' . ($purpose === null ? '' : ' AND purpose = :purpose'))
            ->execute(['now' => $this->clock->now()->format(self::SQL), 'user' => $userId, ...($purpose === null ? [] : ['purpose' => $purpose])]);
    }
}
