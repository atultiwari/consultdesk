<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Crypto;
use ConsultDesk\Infra\Db;
use PDO;

/**
 * "Connect Google Calendar" for one provider: authorisation code flow with PKCE and a one-time,
 * 30-minute state. Asks for offline access so bookings can be managed without the provider present.
 */
final class GoogleOAuth
{
    private const STATE_TTL_MINUTES = 30;
    private const SQL_DATETIME = 'Y-m-d H:i:s';
    private const REQUIRED_SCOPES = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.freebusy',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
    ];

    public function __construct(
        private readonly GoogleApi $api,
        private readonly GoogleConnections $connections,
        private readonly Db $db,
        private readonly Crypto $crypto,
        private readonly Clock $clock,
    ) {}

    /**
     * The link is a bearer credential for 30 minutes, so it is bound to the Google account that is
     * expected to sign in: any other account is refused and its grant revoked.
     *
     * @param bool $allowReplace whether this may replace an existing working connection
     *
     * @return string the Google consent URL to open
     */
    public function start(int $providerId, string $expectedEmail, bool $allowReplace = false): string
    {
        $expectedEmail = strtolower(trim($expectedEmail));
        if (filter_var($expectedEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new GoogleConnectFailed('Give the Google account (email) that should be connected.');
        }

        $state = self::random(24);
        $verifier = self::random(32);
        $now = $this->clock->now();

        $this->db->pdo()->prepare(
            'INSERT INTO google_oauth_states (state_hash, provider_id, verifier_enc, expected_email, allow_replace, expires_at, created_at)
             VALUES (:hash, :provider, :verifier, :email, :replace, :expires, :created)',
        )->execute([
            'hash' => hash('sha256', $state),
            'provider' => $providerId,
            'verifier' => $this->crypto->encrypt($verifier),
            'email' => $expectedEmail,
            'replace' => $allowReplace ? 1 : 0,
            'expires' => $now->modify(sprintf('+%d minutes', self::STATE_TTL_MINUTES))->format(self::SQL_DATETIME),
            'created' => $now->format(self::SQL_DATETIME),
        ]);

        return $this->api->authorizationUrl($state, self::base64url(hash('sha256', $verifier, true)), $expectedEmail);
    }

    /**
     * Finishes the flow from Google's redirect. Returns the connected Google account's email.
     *
     * @throws GoogleConnectFailed
     */
    public function complete(string $state, string $code): string
    {
        $claim = $this->claimState($state);

        try {
            $tokens = $this->api->exchangeCode($code, $claim['verifier']);
        } catch (GoogleApiError) {
            throw new GoogleConnectFailed('Google did not accept the sign-in. Please start again.');
        }
        $grant = $tokens->refreshToken;
        if ($grant === null) {
            throw new GoogleConnectFailed('Google did not grant offline access. Remove this app under myaccount.google.com/permissions, then connect again.');
        }

        // From here on, every failure revokes the new grant so no live token is left behind.
        try {
            $this->assertScopes($tokens);
            $email = $this->assertAccount($tokens, $claim['email']);
            $this->assertMayReplace($claim['provider'], $claim['replace']);
            $primary = $this->primaryCalendar($tokens->accessToken) ?? $email;
        } catch (GoogleConnectFailed $e) {
            $this->revokeQuietly($grant);
            throw $e;
        } catch (GoogleApiError) {
            $this->revokeQuietly($grant);
            throw new GoogleConnectFailed('Could not read your calendars from Google. Please try again.');
        }

        $previous = $this->connections->find($claim['provider']);
        $this->connections->connect($claim['provider'], $email, $tokens, [$primary], $primary);
        if ($previous !== null) {
            $this->revokeQuietly($previous->refreshToken);
        }

        return $email;
    }

    /**
     * Deletes OAuth states that were used or expired more than a day ago.
     */
    public function prune(): int
    {
        $statement = $this->db->pdo()->prepare('DELETE FROM google_oauth_states WHERE expires_at < :cutoff');
        $statement->execute(['cutoff' => $this->clock->now()->modify('-1 day')->format(self::SQL_DATETIME)]);

        return $statement->rowCount();
    }

    private function assertScopes(GoogleTokens $tokens): void
    {
        if (array_diff(self::REQUIRED_SCOPES, explode(' ', $tokens->scope)) !== []) {
            throw new GoogleConnectFailed('Calendar access was not allowed. Connect again and tick all the calendar permissions.');
        }
    }

    private function assertAccount(GoogleTokens $tokens, string $expectedEmail): string
    {
        $email = $this->api->accountEmail($tokens->accessToken);
        if ($email === null) {
            throw new GoogleConnectFailed('Google did not share the account email. Please try again.');
        }
        if ($email !== $expectedEmail) {
            throw new GoogleConnectFailed(sprintf('This link is for %s, but you signed in as a different Google account. Sign in as %s and use a new link.', $expectedEmail, $expectedEmail));
        }

        return $email;
    }

    private function assertMayReplace(int $providerId, bool $allowReplace): void
    {
        if (!$allowReplace && $this->connections->find($providerId)?->active === true) {
            throw new GoogleConnectFailed('This provider is already connected to Google. Ask for a link made with --replace to switch accounts.');
        }
    }

    /**
     * @return array{provider: int, verifier: string, email: string, replace: bool}
     */
    private function claimState(string $state): array
    {
        $row = $this->db->transaction(function (PDO $pdo) use ($state): ?array {
            $statement = $pdo->prepare(
                'SELECT provider_id, verifier_enc, expected_email, allow_replace FROM google_oauth_states
                 WHERE state_hash = :hash AND used_at IS NULL AND expires_at > :now FOR UPDATE',
            );
            $statement->execute(['hash' => hash('sha256', $state), 'now' => $this->clock->now()->format(self::SQL_DATETIME)]);
            $found = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($found)) {
                return null;
            }
            $pdo->prepare('UPDATE google_oauth_states SET used_at = :now WHERE state_hash = :hash')
                ->execute(['now' => $this->clock->now()->format(self::SQL_DATETIME), 'hash' => hash('sha256', $state)]);

            return $found;
        });

        if ($row === null) {
            throw new GoogleConnectFailed('This connection link has expired or was already used. Please start again.');
        }

        return [
            'provider' => (int) $row['provider_id'],
            'verifier' => $this->crypto->decrypt((string) $row['verifier_enc']),
            'email' => (string) $row['expected_email'],
            'replace' => (bool) $row['allow_replace'],
        ];
    }

    private function primaryCalendar(string $accessToken): ?string
    {
        foreach ($this->api->calendars($accessToken) as $calendar) {
            if ($calendar->primary) {
                return $calendar->id;
            }
        }

        return null;
    }

    private function revokeQuietly(string $token): void
    {
        try {
            $this->api->revoke($token);
        } catch (GoogleApiError) {
            // Best effort: the token is not stored either way.
        }
    }

    /**
     * @param positive-int $bytes
     */
    private static function random(int $bytes): string
    {
        return self::base64url(random_bytes($bytes));
    }

    private static function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
