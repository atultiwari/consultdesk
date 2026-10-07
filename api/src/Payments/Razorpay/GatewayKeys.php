<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Crypto;
use PDO;

/**
 * Razorpay keys in payment_gateways: the organisation's (provider_id NULL) and any provider's own.
 * Secrets are encrypted with sodium and only decrypted to make a request. Default organisation keys
 * from .env stand in when none are saved here.
 */
final class GatewayKeys
{
    private const GATEWAY = 'razorpay';
    private const SQL = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
        private readonly Clock $clock,
        private readonly ?RazorpayCredentials $defaults = null,
    ) {}

    /**
     * The organisation's keys from .env, if any.
     */
    public function defaults(): ?RazorpayCredentials
    {
        return $this->defaults;
    }

    /**
     * Where the organisation's keys in use come from: "settings" (saved here), "env" or null.
     */
    public function orgSource(): ?string
    {
        return $this->saved(null) !== null ? 'settings' : ($this->defaults !== null ? 'env' : null);
    }

    /**
     * The account a provider's bookings are paid into: their own if they have one, else the organisation's.
     */
    public function forProvider(int $providerId): ?RazorpayCredentials
    {
        return $this->find($providerId) ?? $this->find(null);
    }

    /**
     * Exactly this owner's keys (null = the organisation's, saved here or else from .env), without
     * falling back from a provider to the organisation.
     */
    public function find(?int $providerId): ?RazorpayCredentials
    {
        return $this->saved($providerId) ?? ($providerId === null ? $this->defaults : null);
    }

    /**
     * Exactly the keys saved here for this owner (null = the organisation), ignoring .env.
     */
    public function saved(?int $providerId): ?RazorpayCredentials
    {
        $statement = $this->pdo->prepare(
            'SELECT provider_id, key_id, secret_enc, webhook_secret_enc FROM payment_gateways
             WHERE gateway = :gateway AND active = 1 AND ' . ($providerId === null ? 'provider_id IS NULL' : 'provider_id = :provider') . '
             ORDER BY id DESC LIMIT 1',
        );
        $statement->execute(['gateway' => self::GATEWAY, ...($providerId === null ? [] : ['provider' => $providerId])]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * The account with this key id, wherever it is used.
     */
    public function byKeyId(string $keyId): ?RazorpayCredentials
    {
        $statement = $this->pdo->prepare('SELECT provider_id, key_id, secret_enc, webhook_secret_enc FROM payment_gateways WHERE gateway = :gateway AND key_id = :key ORDER BY id DESC LIMIT 1');
        $statement->execute(['gateway' => self::GATEWAY, 'key' => $keyId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : ($this->defaults?->keyId === $keyId ? $this->defaults : null);
    }

    /**
     * Unpaid payment links made with this owner's account that customers can still pay.
     */
    public function openLinks(?int $providerId): int
    {
        $account = $this->find($providerId);
        if ($account === null) {
            return 0;
        }
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bookings WHERE gateway_key_id = :key AND status = 'held' AND hold_expires_at > :now",
        );
        $statement->execute(['key' => $account->keyId, 'now' => $this->clock->now()->format(self::SQL)]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Every configured account, e.g. to check which one signed a webhook.
     *
     * @return list<RazorpayCredentials>
     */
    public function all(): array
    {
        $statement = $this->pdo->prepare('SELECT provider_id, key_id, secret_enc, webhook_secret_enc FROM payment_gateways WHERE gateway = :gateway AND active = 1 ORDER BY id');
        $statement->execute(['gateway' => self::GATEWAY]);

        $accounts = array_values(array_map($this->hydrate(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
        $known = array_map(static fn(RazorpayCredentials $c): string => $c->keyId, $accounts);
        if ($this->defaults !== null && !in_array($this->defaults->keyId, $known, true)) {
            $accounts[] = $this->defaults;
        }

        return $accounts;
    }

    /**
     * Key ids of every provider with their own account (no secrets).
     *
     * @return list<array{provider_id: int, provider_name: string, key_id: string, has_webhook_secret: bool}>
     */
    public function overrides(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT g.provider_id, p.name, g.key_id, g.webhook_secret_enc IS NOT NULL AS has_webhook FROM payment_gateways g
             JOIN providers p ON p.id = g.provider_id WHERE g.gateway = :gateway AND g.active = 1 ORDER BY p.name',
        );
        $statement->execute(['gateway' => self::GATEWAY]);

        return array_values(array_map(static fn(array $r): array => [
            'provider_id' => (int) $r['provider_id'],
            'provider_name' => (string) $r['name'],
            'key_id' => (string) $r['key_id'],
            'has_webhook_secret' => (bool) $r['has_webhook'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Replaces the keys for the organisation (null) or one provider, in one step. With a null webhook
     * secret, the current one is kept if the key id is the same account's.
     *
     * @return bool whether a webhook secret is now stored
     */
    public function save(?int $providerId, string $keyId, #[\SensitiveParameter] string $keySecret, #[\SensitiveParameter] ?string $webhookSecret): bool
    {
        // Only a secret saved here carries over; one from .env stays in .env.
        $current = $this->saved($providerId);
        $webhookSecret ??= $current !== null && $current->keyId === $keyId ? $current->webhookSecret : null;
        $this->pdo->beginTransaction();
        try {
            $this->delete($providerId);
            $this->insert($providerId, $keyId, $keySecret, $webhookSecret);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $webhookSecret !== null;
    }

    private function insert(?int $providerId, string $keyId, #[\SensitiveParameter] string $keySecret, #[\SensitiveParameter] ?string $webhookSecret): void
    {
        $now = $this->clock->now()->format(self::SQL);
        $this->pdo->prepare(
            "INSERT INTO payment_gateways (provider_id, gateway, mode, key_id, secret_enc, webhook_secret_enc, active, created_at, updated_at)
             VALUES (:provider, :gateway, :mode, :key, :secret, :webhook, 1, :created, :updated)",
        )->execute([
            'provider' => $providerId,
            'gateway' => self::GATEWAY,
            'mode' => str_starts_with($keyId, 'rzp_live_') ? 'live' : 'test',
            'key' => $keyId,
            'secret' => $this->crypto->encrypt($keySecret),
            'webhook' => $webhookSecret === null ? null : $this->crypto->encrypt($webhookSecret),
            'created' => $now,
            'updated' => $now,
        ]);
    }

    public function setWebhookSecret(?int $providerId, #[\SensitiveParameter] string $webhookSecret): void
    {
        $this->pdo->prepare(
            'UPDATE payment_gateways SET webhook_secret_enc = :webhook, updated_at = :now
             WHERE gateway = :gateway AND ' . ($providerId === null ? 'provider_id IS NULL' : 'provider_id = :provider'),
        )->execute([
            'webhook' => $this->crypto->encrypt($webhookSecret),
            'now' => $this->clock->now()->format(self::SQL),
            'gateway' => self::GATEWAY,
            ...($providerId === null ? [] : ['provider' => $providerId]),
        ]);
    }

    public function delete(?int $providerId): void
    {
        $this->pdo->prepare('DELETE FROM payment_gateways WHERE gateway = :gateway AND ' . ($providerId === null ? 'provider_id IS NULL' : 'provider_id = :provider'))
            ->execute(['gateway' => self::GATEWAY, ...($providerId === null ? [] : ['provider' => $providerId])]);
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): RazorpayCredentials
    {
        return new RazorpayCredentials(
            (string) $r['key_id'],
            $this->crypto->decrypt((string) $r['secret_enc']),
            $r['webhook_secret_enc'] === null ? null : $this->crypto->decrypt((string) $r['webhook_secret_enc']),
            $r['provider_id'] === null ? null : (int) $r['provider_id'],
        );
    }
}
