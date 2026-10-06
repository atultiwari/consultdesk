<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Crypto;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Each provider's Google connection (oauth_tokens). Tokens are stored encrypted with APP_KEY.
 */
final class GoogleConnections
{
    private const SQL_DATETIME = 'Y-m-d H:i:s';
    private const PROVIDER = 'google';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        private readonly Clock $clock,
    ) {}

    public function find(int $providerId): ?GoogleConnection
    {
        $statement = $this->pdo->prepare(
            'SELECT provider_id, account_email, refresh_token_enc, access_token_enc, access_expires_at,
                busy_calendar_ids, target_calendar_id, status
             FROM oauth_tokens WHERE provider_id = :provider AND oauth_provider = :name',
        );
        $statement->execute(['provider' => $providerId, 'name' => self::PROVIDER]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $busy = json_decode((string) ($row['busy_calendar_ids'] ?? '[]'), true);

        return new GoogleConnection(
            (int) $row['provider_id'],
            $row['account_email'] === null ? null : (string) $row['account_email'],
            $this->crypto->decrypt((string) $row['refresh_token_enc']),
            $row['access_token_enc'] === null ? null : $this->crypto->decrypt((string) $row['access_token_enc']),
            $row['access_expires_at'] === null ? null : new DateTimeImmutable((string) $row['access_expires_at'], new DateTimeZone('UTC')),
            is_array($busy) ? array_values(array_filter($busy, 'is_string')) : [],
            $row['target_calendar_id'] === null ? null : (string) $row['target_calendar_id'],
            $row['status'] === 'active',
            (string) $row['refresh_token_enc'],
        );
    }

    /**
     * Creates or replaces the provider's connection with fresh tokens and calendar choices.
     *
     * @param list<string> $busyCalendarIds
     */
    public function connect(int $providerId, string $accountEmail, GoogleTokens $tokens, array $busyCalendarIds, string $targetCalendarId): void
    {
        $now = $this->clock->now();
        $this->pdo->prepare(
            "INSERT INTO oauth_tokens (provider_id, oauth_provider, account_email, refresh_token_enc, access_token_enc,
                access_expires_at, scopes, busy_calendar_ids, target_calendar_id, status, last_error, broken_notified_at, created_at, updated_at)
             VALUES (:provider, :name, :email, :refresh, :access, :expires, :scopes, :busy, :target, 'active', NULL, NULL, :created, :updated)
             ON DUPLICATE KEY UPDATE account_email = VALUES(account_email), refresh_token_enc = VALUES(refresh_token_enc),
                access_token_enc = VALUES(access_token_enc), access_expires_at = VALUES(access_expires_at), scopes = VALUES(scopes),
                busy_calendar_ids = VALUES(busy_calendar_ids), target_calendar_id = VALUES(target_calendar_id),
                status = 'active', last_error = NULL, broken_notified_at = NULL, updated_at = VALUES(updated_at)",
        )->execute([
            'provider' => $providerId,
            'name' => self::PROVIDER,
            'email' => $accountEmail,
            'refresh' => $this->crypto->encrypt((string) $tokens->refreshToken),
            'access' => $this->crypto->encrypt($tokens->accessToken),
            'expires' => $this->expiry($tokens)->format(self::SQL_DATETIME),
            'scopes' => mb_substr($tokens->scope, 0, 500),
            'busy' => json_encode($busyCalendarIds, JSON_THROW_ON_ERROR),
            'target' => $targetCalendarId,
            'created' => $now->format(self::SQL_DATETIME),
            'updated' => $now->format(self::SQL_DATETIME),
        ]);
    }

    /**
     * Stores a refreshed access token, unless the provider has reconnected since $connection was read.
     */
    public function saveAccessToken(GoogleConnection $connection, GoogleTokens $tokens): void
    {
        $this->pdo->prepare(
            'UPDATE oauth_tokens SET access_token_enc = :access, access_expires_at = :expires, updated_at = :now
             WHERE provider_id = :provider AND oauth_provider = :name AND refresh_token_enc = :version',
        )->execute([
            'access' => $this->crypto->encrypt($tokens->accessToken),
            'expires' => $this->expiry($tokens)->format(self::SQL_DATETIME),
            'now' => $this->clock->now()->format(self::SQL_DATETIME),
            'provider' => $connection->providerId,
            'name' => self::PROVIDER,
            'version' => $connection->version,
        ]);
    }

    /**
     * @param list<string> $busyCalendarIds
     */
    public function setCalendars(int $providerId, array $busyCalendarIds, string $targetCalendarId): void
    {
        $this->pdo->prepare(
            'UPDATE oauth_tokens SET busy_calendar_ids = :busy, target_calendar_id = :target, updated_at = :now
             WHERE provider_id = :provider AND oauth_provider = :name',
        )->execute([
            'busy' => json_encode($busyCalendarIds, JSON_THROW_ON_ERROR),
            'target' => $targetCalendarId,
            'now' => $this->clock->now()->format(self::SQL_DATETIME),
            'provider' => $providerId,
            'name' => self::PROVIDER,
        ]);
    }

    /**
     * Marks the connection broken, unless the provider has reconnected since $connection was read.
     *
     * @return bool true only for the call that changed it from active to broken
     */
    public function markBroken(GoogleConnection $connection, string $error): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE oauth_tokens SET status = 'broken', last_error = :error, access_token_enc = NULL, broken_notified_at = :now, updated_at = :now2
             WHERE provider_id = :provider AND oauth_provider = :name AND status = 'active' AND refresh_token_enc = :version",
        );
        $now = $this->clock->now()->format(self::SQL_DATETIME);
        $statement->execute([
            'error' => mb_substr($error, 0, 500),
            'now' => $now,
            'now2' => $now,
            'provider' => $connection->providerId,
            'name' => self::PROVIDER,
            'version' => $connection->version,
        ]);

        return $statement->rowCount() === 1;
    }

    public function delete(int $providerId): void
    {
        $this->pdo->prepare('DELETE FROM oauth_tokens WHERE provider_id = :provider AND oauth_provider = :name')
            ->execute(['provider' => $providerId, 'name' => self::PROVIDER]);
    }

    private function expiry(GoogleTokens $tokens): DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('+%d seconds', $tokens->expiresIn));
    }
}
