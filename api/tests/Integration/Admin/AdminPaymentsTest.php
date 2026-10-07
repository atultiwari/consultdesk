<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Integration\Support\Fixtures;
use ConsultDesk\Tests\Support\FakeRazorpayApi;

final class AdminPaymentsTest extends AdminTestCase
{
    private int $demo;
    private FakeRazorpayApi $fake;
    private bool $live = false;

    protected function extraEnv(): array
    {
        return [...parent::extraEnv(), ...($this->live ? ['PAYMENTS_LIVE' => '1'] : [])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeRazorpayApi();
        $this->razorpay = $this->fake;
        $this->demo = Fixtures::provider($this->pdo, ['slug' => 'demo'], openAllWeek: false);
    }

    public function testOwnersSwitchPaymentMethodsOnAndOff(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [, $initial] = $this->admin('GET', '/api/admin/payments');
        self::assertSame(['upi_enabled' => true, 'razorpay_enabled' => true], $initial['data']['methods']);
        self::assertFalse($initial['data']['razorpay']['configured']);
        self::assertSame(self::APP_URL . '/api/webhooks/razorpay', $initial['data']['razorpay']['webhook_url']);

        [$status, $saved] = $this->admin('PUT', '/api/admin/payments/methods', ['upi_enabled' => false, 'razorpay_enabled' => true]);
        self::assertSame([200, false], [$status, $saved['data']['methods']['upi_enabled']]);
        self::assertSame(422, $this->admin('PUT', '/api/admin/payments/methods', ['upi_enabled' => 'no'])[0]);
    }

    public function testOrganisationKeysAreTestModeOnlyAndStoredEncrypted(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $keyId = 'rzp_test_' . str_repeat('A', 14);
        $secret = str_repeat('s', 24);

        [$status, $body] = $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => $keyId, 'key_secret' => $secret]);
        self::assertSame(200, $status, json_encode($body) ?: '');
        self::assertSame(['configured' => true, 'mode' => 'test', 'key_id' => $keyId], array_intersect_key($body['data']['razorpay'], ['configured' => 1, 'mode' => 1, 'key_id' => 1]));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32,}$/', $body['data']['razorpay']['webhook_secret'], 'shown once, to paste into Razorpay');
        self::assertStringNotContainsString($secret, (string) json_encode($body));
        self::assertSame(['0'], self::column($this->pdo, 'SELECT COUNT(*) FROM payment_gateways WHERE secret_enc LIKE :s', ['s' => '%' . $secret . '%']), 'never stored in plain text');
        self::assertArrayNotHasKey('webhook_secret', $this->admin('GET', '/api/admin/payments')[1]['data']['razorpay']);

        [$live, $errors] = $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => 'rzp_live_' . str_repeat('A', 14), 'key_secret' => $secret]);
        self::assertSame([422, ['key_id']], [$live, array_keys($errors['error']['fields'])]);

        self::assertSame(200, $this->admin('POST', '/api/admin/payments/razorpay/check')[0]);
        $this->fake->rejectKeys = true;
        self::assertSame([422, 'razorpay_rejected'], $this->codeOf($this->admin('POST', '/api/admin/payments/razorpay/check')));

        [, $resaved] = $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => $keyId, 'key_secret' => $secret]);
        self::assertArrayNotHasKey('webhook_secret', $resaved['data']['razorpay'], 're-saving the same account keeps the webhook working');

        [, $rotated] = $this->admin('POST', '/api/admin/payments/razorpay/webhook-secret');
        self::assertNotSame($body['data']['razorpay']['webhook_secret'], $rotated['data']['razorpay']['webhook_secret']);

        self::assertFalse($this->admin('DELETE', '/api/admin/payments/razorpay')[1]['data']['razorpay']['configured']);
        self::assertGreaterThanOrEqual(3, (int) (self::column($this->pdo, "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'admin.razorpay_%'")[0] ?? 0));
    }

    public function testKeysCanNotBeRemovedWhileCustomersHaveLinksToPay(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $keyId = 'rzp_test_' . str_repeat('A', 14);
        $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => $keyId, 'key_secret' => str_repeat('s', 24)]);
        $service = Fixtures::service($this->pdo, $this->demo, ['payment_methods' => '["razorpay_link"]']);
        $this->pdo->exec("INSERT INTO bookings (ref, public_token_hash, provider_id, service_id, start_at, end_at, customer_name, customer_email, answers,
            amount_minor, payment_method, gateway_ref, gateway_key_id, status, hold_expires_at, status_changed_at, created_at, updated_at)
            VALUES ('CD-OPEN', REPEAT('a', 64), {$this->demo}, {$service}, '2026-10-07 04:30:00', '2026-10-07 05:30:00', 'X', 'x@example.test', '{}',
            149900, 'razorpay_link', 'plink_open', '{$keyId}', 'held', '2026-10-05 00:20:00', NOW(), NOW(), NOW())");

        self::assertSame([409, 'links_open'], $this->codeOf($this->admin('DELETE', '/api/admin/payments/razorpay')));
    }

    public function testOnlinePaymentCanBeOfferedOnEveryEligibleSessionAtOnce(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $paid = Fixtures::service($this->pdo, $this->demo, ['slug' => 'paid', 'payment_methods' => '["upi"]']);
        $already = Fixtures::service($this->pdo, $this->demo, ['slug' => 'already', 'payment_methods' => '["upi","razorpay_link"]']);
        $free = Fixtures::service($this->pdo, $this->demo, ['slug' => 'free', 'price_minor' => 0, 'payment_methods' => '["free"]']);
        $approval = Fixtures::service($this->pdo, $this->demo, ['slug' => 'approval', 'payment_methods' => '["upi"]', 'requires_approval' => 1]);

        [$status, $body] = $this->admin('POST', '/api/admin/payments/razorpay/offer-everywhere');

        self::assertSame([200, 1], [$status, $body['data']['sessions_updated']]);
        $methods = fn(int $id): array => json_decode((string) (self::column($this->pdo, 'SELECT payment_methods FROM services WHERE id = :id', ['id' => $id])[0] ?? '[]'), true);
        self::assertSame(['upi', 'razorpay_link'], $methods($paid));
        self::assertSame(['upi', 'razorpay_link'], $methods($already));
        self::assertSame(['free'], $methods($free));
        self::assertSame(['upi'], $methods($approval), 'paying online would skip the approval');
    }

    public function testATeacherCanHaveTheirOwnRazorpayAccount(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $keyId = 'rzp_test_' . str_repeat('T', 14);

        [$status, $body] = $this->admin('PUT', "/api/admin/providers/{$this->demo}/razorpay", ['key_id' => $keyId, 'key_secret' => str_repeat('t', 24)]);
        self::assertSame(200, $status);
        self::assertSame($keyId, $body['data']['key_id']);
        self::assertSame([['provider_id' => $this->demo, 'key_id' => $keyId]], array_map(
            static fn(array $o): array => ['provider_id' => $o['provider_id'], 'key_id' => $o['key_id']],
            $this->admin('GET', '/api/admin/payments')[1]['data']['overrides'],
        ));
        self::assertSame(200, $this->admin('DELETE', "/api/admin/providers/{$this->demo}/razorpay")[0]);
        self::assertSame([], $this->admin('GET', '/api/admin/payments')[1]['data']['overrides']);
    }

    public function testOnlyOwnersTouchPaymentSettings(): void
    {
        $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');

        self::assertSame(403, $this->admin('GET', '/api/admin/payments')[0]);
        self::assertSame(403, $this->admin('PUT', "/api/admin/providers/{$this->demo}/razorpay", ['key_id' => 'rzp_test_' . str_repeat('A', 14), 'key_secret' => str_repeat('s', 24)])[0]);
    }

    public function testLiveKeysAreAcceptedOnceTheServerSaysPaymentsAreLive(): void
    {
        $this->live = true;
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => 'rzp_live_' . str_repeat('L', 14), 'key_secret' => str_repeat('s', 24)]);

        self::assertSame(200, $status, json_encode($body) ?: '');
        self::assertSame(['live', true], [$body['data']['razorpay']['mode'], $body['data']['razorpay']['live_allowed']]);
    }

    /**
     * @param array{int, array<string, mixed>, mixed} $result
     *
     * @return array{int, string}
     */
    private function codeOf(array $result): array
    {
        return [$result[0], (string) ($result[1]['error']['code'] ?? '')];
    }
}
