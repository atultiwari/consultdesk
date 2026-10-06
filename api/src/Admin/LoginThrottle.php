<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Infra\Clock;
use PDO;

/**
 * Slows down password guessing without letting a stranger lock the owner out (docs/PLAN.md §6.7).
 * Within 15 minutes:
 *  - 5 failures from one address lock that address, for every account;
 *  - 20 failures for one account from anywhere lock it, except from addresses where it has signed
 *    in successfully in the last 30 days.
 * Attempts are forgotten after a day.
 */
final class LoginThrottle
{
    private const MAX_PER_ADDRESS = 5;
    private const MAX_PER_ACCOUNT = 20;
    private const WINDOW_MINUTES = 15;
    private const KNOWN_ADDRESS_DAYS = 30;
    private const KEEP_HOURS = 24;
    private const SQL = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function isLocked(string $ip, string $email): bool
    {
        $now = $this->clock->now();
        $since = $now->modify(sprintf('-%d minutes', self::WINDOW_MINUTES))->format(self::SQL);
        $statement = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM login_attempts WHERE ip = :ip1 AND succeeded = 0 AND attempted_at > :since1),
                (SELECT COUNT(*) FROM login_attempts WHERE email = :email1 AND succeeded = 0 AND attempted_at > :since2),
                (SELECT COUNT(*) FROM login_attempts WHERE ip = :ip2 AND email = :email2 AND succeeded = 1 AND attempted_at > :known)',
        );
        $statement->execute([
            'ip1' => self::ip($ip),
            'ip2' => self::ip($ip),
            'email1' => self::email($email),
            'email2' => self::email($email),
            'since1' => $since,
            'since2' => $since,
            'known' => $now->modify(sprintf('-%d days', self::KNOWN_ADDRESS_DAYS))->format(self::SQL),
        ]);
        [$fromAddress, $forAccount, $knownAddress] = array_map('intval', $statement->fetch(PDO::FETCH_NUM) ?: [0, 0, 0]);

        return $fromAddress >= self::MAX_PER_ADDRESS
            || ($forAccount >= self::MAX_PER_ACCOUNT && $knownAddress === 0);
    }

    public function record(string $ip, string $email, bool $succeeded): void
    {
        $this->pdo->prepare('INSERT INTO login_attempts (ip, email, succeeded, attempted_at) VALUES (:ip, :email, :ok, :now)')
            ->execute([
                'ip' => self::ip($ip),
                'email' => self::email($email),
                'ok' => $succeeded ? 1 : 0,
                'now' => $this->clock->now()->format(self::SQL),
            ]);
    }

    /**
     * Lifts a lockout, e.g. after the password was reset from the shell.
     */
    public function clear(string $email): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :email AND succeeded = 0')->execute(['email' => self::email($email)]);
    }

    /**
     * Cron: forgets failed attempts after a day; successful ones are kept while they mark a known address.
     */
    public function prune(): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE (succeeded = 0 AND attempted_at < :failed) OR attempted_at < :known',
        );
        $now = $this->clock->now();
        $statement->execute([
            'failed' => $now->modify(sprintf('-%d hours', self::KEEP_HOURS))->format(self::SQL),
            'known' => $now->modify(sprintf('-%d days', self::KNOWN_ADDRESS_DAYS))->format(self::SQL),
        ]);

        return $statement->rowCount();
    }

    private static function email(string $email): string
    {
        return mb_substr(strtolower(trim($email)), 0, 254);
    }

    private static function ip(string $ip): string
    {
        // ClientIp gives an address or "x::/64"; store the packed network part.
        $packed = @inet_pton(explode('/', $ip)[0]);

        return $packed === false ? str_repeat("\0", 16) : $packed;
    }
}
