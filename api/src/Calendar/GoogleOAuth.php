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
    ];

    public function __construct(
        private readonly GoogleApi $api,
        private readonly GoogleConnections $connections,
        private readonly Db $db,
        private readonly Crypto $crypto,
        private readonly Clock $clock,
    ) {}

    /**
     * @return string the Google consent URL to open
     */
    public function start(int $providerId): string
    {
        $state = self::random(24);
        $verifier = self::random(32);
        $now = $this->clock->now();

        $this->db->pdo()->prepare(
            'INSERT INTO google_oauth_states (state_hash, provider_id, verifier_enc, expires_at, created_at)
             VALUES (:hash, :provider, :verifier, :expires, :created)',
        )->execute([
            'hash' => hash('sha256', $state),
            'provider' => $providerId,
            'verifier' => $this->crypto->encrypt($verifier),
            'expires' => $now->modify(sprintf('+%d minutes', self::STATE_TTL_MINUTES))->format(self::SQL_DATETIME),
            'created' => $now->format(self::SQL_DATETIME),
        ]);

        return $this->api->authorizationUrl($state, self::base64url(hash('sha256', $verifier, true)));
    }

    /**
     * Finishes the flow from Google's redirect. Returns the connected Google account's email.
     *
     * @throws GoogleConnectFailed
     */
    public function complete(string $state, string $code): string
    {
        [$providerId, $verifier] = $this->claimState($state);

        try {
            $tokens = $this->api->exchangeCode($code, $verifier);
        } catch (GoogleApiError) {
            throw new GoogleConnectFailed('Google did not accept the sign-in. Please start again.');
        }
        if ($tokens->refreshToken === null) {
            throw new GoogleConnectFailed('Google did not grant offline access. Remove this app under myaccount.google.com/permissions, then connect again.');
        }
        $granted = explode(' ', $tokens->scope);
        if (array_diff(self::REQUIRED_SCOPES, $granted) !== []) {
            $this->revokeQuietly($tokens->refreshToken);
            throw new GoogleConnectFailed('Calendar access was not allowed. Connect again and tick both calendar permissions.');
        }

        try {
            $email = $this->api->accountEmail($tokens->accessToken) ?? 'unknown';
            $primary = $this->primaryCalendar($tokens->accessToken) ?? $email;
        } catch (GoogleApiError) {
            throw new GoogleConnectFailed('Could not read your calendars from Google. Please try again.');
        }

        $this->connections->connect($providerId, $email, $tokens, [$primary], $primary);

        return $email;
    }

    /**
     * @return array{int, string} provider id and PKCE verifier
     */
    private function claimState(string $state): array
    {
        $row = $this->db->transaction(function (PDO $pdo) use ($state): ?array {
            $statement = $pdo->prepare(
                'SELECT provider_id, verifier_enc FROM google_oauth_states
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

        return [(int) $row['provider_id'], $this->crypto->decrypt((string) $row['verifier_enc'])];
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
