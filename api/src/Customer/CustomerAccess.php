<?php

declare(strict_types=1);

namespace ConsultDesk\Customer;

use ConsultDesk\Infra\Clock;
use ConsultDesk\Notify\Outbox;
use PDO;

/**
 * Customer sign-in by email link. Asking for a link never says whether the address has bookings;
 * the email job makes the token (so it's never stored in the queue) and sends nothing to an address
 * without bookings. A link works once, for 15 minutes; a session lasts 30 days.
 */
final class CustomerAccess
{
    public const EMAIL_JOB = 'email.customer_link';
    public const LINK_MINUTES = 15;
    private const SESSION_DAYS = 30;
    /** Links one address can be sent per hour, however often it's asked for. */
    private const LINKS_PER_HOUR = 3;
    private const SQL = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Outbox $outbox,
        private readonly Clock $clock,
    ) {}

    public function requestLink(string $email): void
    {
        $this->outbox->enqueue(self::EMAIL_JOB, ['email' => self::normalise($email)]);
    }

    /**
     * Called by the email job: a new link token, or null when the address has no bookings or has
     * had enough links this hour.
     */
    public function issue(string $email): ?string
    {
        $email = self::normalise($email);
        $now = $this->clock->now();
        if (!$this->hasBookings($email) || $this->linksSince($email, $now->modify('-1 hour')) >= self::LINKS_PER_HOUR) {
            return null;
        }
        $token = self::token();
        $this->pdo->prepare(
            'INSERT INTO customer_links (email, token_hash, expires_at, created_at) VALUES (:email, :hash, :expires, :created)',
        )->execute([
            'email' => $email,
            'hash' => hash('sha256', $token),
            'expires' => $now->modify(sprintf('+%d minutes', self::LINK_MINUTES))->format(self::SQL),
            'created' => $now->format(self::SQL),
        ]);

        return $token;
    }

    /**
     * Uses up a link and starts a session.
     *
     * @return array{token: string, email: string}|null null for an unknown, used or expired link
     */
    public function signIn(#[\SensitiveParameter] string $linkToken): ?array
    {
        $now = $this->clock->now();
        // The UPDATE claims the link, so two clicks on the same link can't both sign in.
        $claim = $this->pdo->prepare(
            'UPDATE customer_links SET used_at = :now WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now2',
        );
        $claim->execute(['now' => $now->format(self::SQL), 'now2' => $now->format(self::SQL), 'hash' => hash('sha256', $linkToken)]);
        if ($claim->rowCount() !== 1) {
            return null;
        }
        $find = $this->pdo->prepare('SELECT email FROM customer_links WHERE token_hash = :hash');
        $find->execute(['hash' => hash('sha256', $linkToken)]);
        $email = (string) $find->fetchColumn();

        $token = self::token();
        $this->pdo->prepare('INSERT INTO customer_sessions (id, email, created_at, expires_at) VALUES (:id, :email, :created, :expires)')->execute([
            'id' => hash('sha256', $token),
            'email' => $email,
            'created' => $now->format(self::SQL),
            'expires' => $now->modify(sprintf('+%d days', self::SESSION_DAYS))->format(self::SQL),
        ]);

        return ['token' => $token, 'email' => $email];
    }

    /**
     * The signed-in customer's email, or null.
     */
    public function emailFor(#[\SensitiveParameter] string $sessionToken): ?string
    {
        if ($sessionToken === '') {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT email FROM customer_sessions WHERE id = :id AND expires_at > :now');
        $statement->execute(['id' => hash('sha256', $sessionToken), 'now' => $this->clock->now()->format(self::SQL)]);
        $email = $statement->fetchColumn();

        return is_string($email) ? $email : null;
    }

    public function signOut(#[\SensitiveParameter] string $sessionToken): void
    {
        $this->pdo->prepare('DELETE FROM customer_sessions WHERE id = :id')->execute(['id' => hash('sha256', $sessionToken)]);
    }

    /**
     * Removes used and expired links and sessions (run by cron).
     */
    public function prune(): int
    {
        $now = $this->clock->now()->format(self::SQL);
        $links = $this->pdo->prepare('DELETE FROM customer_links WHERE expires_at < :cutoff');
        $links->execute(['cutoff' => $this->clock->now()->modify('-1 day')->format(self::SQL)]);
        $sessions = $this->pdo->prepare('DELETE FROM customer_sessions WHERE expires_at < :now');
        $sessions->execute(['now' => $now]);

        return $links->rowCount() + $sessions->rowCount();
    }

    private function hasBookings(string $email): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM bookings WHERE customer_email = :email LIMIT 1');
        $statement->execute(['email' => $email]);

        return $statement->fetchColumn() !== false;
    }

    private function linksSince(string $email, \DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM customer_links WHERE email = :email AND created_at > :since');
        $statement->execute(['email' => $email, 'since' => $since->format(self::SQL)]);

        return (int) $statement->fetchColumn();
    }

    private static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
