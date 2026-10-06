<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Infra\Clock;
use PDO;

/**
 * Five failed sign-ins in 15 minutes locks that account and that address for the rest of the window
 * (docs/PLAN.md §6.7), whether or not the account exists.
 */
final class LoginThrottle
{
    private const MAX_FAILURES = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function isLocked(string $ip, string $email): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND succeeded = 0 AND attempted_at > :since1),
                (SELECT COUNT(*) FROM login_attempts WHERE email = :email AND succeeded = 0 AND attempted_at > :since2)',
        );
        $since = $this->clock->now()->modify(sprintf('-%d minutes', self::WINDOW_MINUTES))->format('Y-m-d H:i:s');
        $statement->execute(['ip' => self::ip($ip), 'email' => strtolower(trim($email)), 'since1' => $since, 'since2' => $since]);
        $counts = $statement->fetch(PDO::FETCH_NUM);

        return is_array($counts) && max((int) $counts[0], (int) $counts[1]) >= self::MAX_FAILURES;
    }

    public function record(string $ip, string $email, bool $succeeded): void
    {
        $this->pdo->prepare('INSERT INTO login_attempts (ip, email, succeeded, attempted_at) VALUES (:ip, :email, :ok, :now)')
            ->execute([
                'ip' => self::ip($ip),
                'email' => mb_substr(strtolower(trim($email)), 0, 254),
                'ok' => $succeeded ? 1 : 0,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
    }

    private static function ip(string $ip): string
    {
        // ClientIp gives an address or "x::/64"; store the packed network part.
        $packed = @inet_pton(explode('/', $ip)[0]);

        return $packed === false ? str_repeat("\0", 16) : $packed;
    }
}
