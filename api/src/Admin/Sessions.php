<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Infra\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Server-side admin sessions. The browser holds a random token in an httpOnly cookie; the database
 * holds only its SHA-256. Sessions end after 8 idle hours and always after 30 days.
 */
final class Sessions
{
    public const IDLE_HOURS = 8;
    public const MAX_DAYS = 30;
    private const TOUCH_AFTER_SECONDS = 60;
    private const SQL = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly AdminUsers $users,
        #[\SensitiveParameter]
        private readonly string $csrfKey,
    ) {}

    public function start(AdminUser $user, string $ip, string $userAgent): AdminSession
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, csrf_token_hash, ip, user_agent, created_at, last_seen_at, expires_at)
             VALUES (:id, :user, :csrf, :ip, :ua, :created, :seen, :expires)',
        )->execute([
            'id' => hash('sha256', $token),
            'user' => $user->id,
            'csrf' => hash('sha256', $this->csrfFor($token)),
            'ip' => @inet_pton(explode('/', $ip)[0]) ?: null,
            'ua' => mb_substr($userAgent, 0, 255),
            'created' => $now->format(self::SQL),
            'seen' => $now->format(self::SQL),
            'expires' => $now->modify(sprintf('+%d hours', self::IDLE_HOURS))->format(self::SQL),
        ]);

        return new AdminSession($user, $token, $this->csrfFor($token));
    }

    /**
     * The live session for a cookie token, extending its idle timeout; null if unknown or expired.
     */
    public function resume(#[\SensitiveParameter] string $token): ?AdminSession
    {
        if ($token === '') {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT user_id, created_at, last_seen_at, expires_at FROM sessions WHERE id = :id');
        $statement->execute(['id' => hash('sha256', $token)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $now = $this->clock->now();
        $created = self::utc((string) $row['created_at']);
        $hardEnd = $created->modify(sprintf('+%d days', self::MAX_DAYS));
        if (self::utc((string) $row['expires_at']) <= $now || $hardEnd <= $now) {
            $this->end($token);

            return null;
        }

        $user = $this->users->find((int) $row['user_id']);
        if ($user === null) {
            return null;
        }
        if ($now->getTimestamp() - self::utc((string) $row['last_seen_at'])->getTimestamp() >= self::TOUCH_AFTER_SECONDS) {
            $this->pdo->prepare('UPDATE sessions SET last_seen_at = :seen, expires_at = :expires WHERE id = :id')->execute([
                'seen' => $now->format(self::SQL),
                'expires' => min($now->modify(sprintf('+%d hours', self::IDLE_HOURS)), $hardEnd)->format(self::SQL),
                'id' => hash('sha256', $token),
            ]);
        }

        return new AdminSession($user, $token, $this->csrfFor($token));
    }

    public function end(#[\SensitiveParameter] string $token): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE id = :id')->execute(['id' => hash('sha256', $token)]);
    }

    /**
     * Signs the user out everywhere except this session (after they change their own password).
     */
    public function endOthers(int $userId, #[\SensitiveParameter] string $keepToken): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :user AND id <> :keep')
            ->execute(['user' => $userId, 'keep' => hash('sha256', $keepToken)]);
    }

    public function endAll(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :user')->execute(['user' => $userId]);
    }

    public function prune(): int
    {
        $statement = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at < :now');
        $statement->execute(['now' => $this->clock->now()->format(self::SQL)]);

        return $statement->rowCount();
    }

    /**
     * The CSRF token is derived from the session token, so it never needs storing in clear.
     */
    public function csrfFor(#[\SensitiveParameter] string $token): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'csrf:' . $token, $this->csrfKey, true)), '+/', '-_'), '=');
    }

    private static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
