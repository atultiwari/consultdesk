<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Support\FakeRazorpayApi;

final class RazorpayEnvKeysTest extends AdminTestCase
{
    private const ENV_KEY = 'rzp_test_ENVDEFAULT0000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->razorpay = new FakeRazorpayApi();
    }

    protected function extraEnv(): array
    {
        return [
            ...parent::extraEnv(),
            'RAZORPAY_KEY_ID' => self::ENV_KEY,
            'RAZORPAY_KEY_SECRET' => str_repeat('e', 24),
            'RAZORPAY_WEBHOOK_SECRET' => str_repeat('w', 32),
        ];
    }

    public function testKeysFromEnvAreUsedUntilKeysAreSavedHereAndComeBackWhenThoseAreRemoved(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        $razorpay = $this->admin('GET', '/api/admin/payments')[1]['data']['razorpay'];
        self::assertSame([true, 'env', self::ENV_KEY, true], [$razorpay['configured'], $razorpay['source'], $razorpay['key_id'], $razorpay['has_webhook_secret']]);
        self::assertSame(self::ENV_KEY, $razorpay['env_key_id']);
        self::assertSame(200, $this->admin('POST', '/api/admin/payments/razorpay/check')[0]);
        self::assertSame([409, 'env_keys'], $this->codeOf($this->admin('POST', '/api/admin/payments/razorpay/webhook-secret')));
        self::assertSame([409, 'env_keys'], $this->codeOf($this->admin('DELETE', '/api/admin/payments/razorpay')));

        $own = 'rzp_test_' . str_repeat('O', 14);
        [, $saved] = $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => $own, 'key_secret' => str_repeat('s', 24)]);
        self::assertSame(['settings', $own], [$saved['data']['razorpay']['source'], $saved['data']['razorpay']['key_id']]);

        [, $removed] = $this->admin('DELETE', '/api/admin/payments/razorpay');
        self::assertSame(['env', self::ENV_KEY], [$removed['data']['razorpay']['source'], $removed['data']['razorpay']['key_id']]);
    }

    public function testSavingTheSameAccountHereGetsItsOwnWebhookSecretAndLeavesTheEnvOneInEnv(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [, $saved] = $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::ENV_KEY, 'key_secret' => str_repeat('n', 24)]);

        $fresh = $saved['data']['razorpay']['webhook_secret'] ?? null;
        self::assertIsString($fresh, 'a new secret to paste into Razorpay');
        self::assertNotSame(str_repeat('w', 32), $fresh);
        self::assertSame([$fresh], array_map(static fn($c) => $c->webhookSecret, $this->services()->gatewayKeys()->all()));
    }

    public function testWebhooksSignedWithTheEnvSecretAreRecognised(): void
    {
        $keys = $this->services()->gatewayKeys();

        self::assertSame(self::ENV_KEY, $keys->byKeyId(self::ENV_KEY)?->keyId);
        self::assertSame([str_repeat('w', 32)], array_map(static fn($c) => $c->webhookSecret, $keys->all()));
        self::assertSame(self::ENV_KEY, $keys->forProvider(123)?->keyId, 'a teacher without their own account uses the default');
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
