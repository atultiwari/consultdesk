<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Integration\Support\Fixtures;
use ConsultDesk\Tests\Support\FakeRazorpayApi;

/**
 * Test and live Razorpay keys are kept side by side; the owner's Live switch picks which are used.
 */
final class PaymentModeTest extends AdminTestCase
{
    private const TEST_KEY = 'rzp_test_TTTTTTTTTTTTTT';
    private const LIVE_KEY = 'rzp_live_LLLLLLLLLLLLLL';

    private int $demo;
    private FakeRazorpayApi $fake;
    /** @var array<string, string> */
    private array $config = [];

    protected function extraEnv(): array
    {
        return [...parent::extraEnv(), ...$this->config];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeRazorpayApi();
        $this->razorpay = $this->fake;
        $this->demo = Fixtures::provider($this->pdo, ['slug' => 'demo'], openAllWeek: false);
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
    }

    public function testTestAndLiveKeysAreSavedSideBySideAndTestIsUsedUntilLiveIsOn(): void
    {
        [, $test] = $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::TEST_KEY, 'key_secret' => str_repeat('t', 24)]);
        [$status, $live] = $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::LIVE_KEY, 'key_secret' => str_repeat('l', 24)]);

        self::assertSame(200, $status, json_encode($live) ?: '');
        self::assertIsString($live['data']['razorpay']['webhook_secret'] ?? null, 'live keys get their own webhook secret');
        $razorpay = $live['data']['razorpay'];
        self::assertSame([false, 'test', self::TEST_KEY], [$razorpay['live'], $razorpay['mode'], $razorpay['key_id']]);
        self::assertSame(self::TEST_KEY, $razorpay['accounts']['test']['key_id']);
        self::assertSame(self::LIVE_KEY, $razorpay['accounts']['live']['key_id']);
        self::assertSame(self::TEST_KEY, $this->services()->gatewayKeys()->forProvider($this->demo)?->keyId);
        self::assertCount(2, $this->services()->gatewayKeys()->all(), 'webhooks from both accounts are still recognised');
        self::assertIsString($test['data']['razorpay']['webhook_secret'] ?? null);
    }

    public function testGoingLiveNeedsWorkingLiveKeysAndCanBeUndone(): void
    {
        $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::TEST_KEY, 'key_secret' => str_repeat('t', 24)]);
        self::assertSame([409, 'no_live_keys'], $this->codeOf($this->admin('PUT', '/api/admin/payments/mode', ['live' => true])));

        $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::LIVE_KEY, 'key_secret' => str_repeat('l', 24)]);
        $this->fake->rejectKeys = true;
        self::assertSame([422, 'razorpay_rejected'], $this->codeOf($this->admin('PUT', '/api/admin/payments/mode', ['live' => true])));
        $this->fake->rejectKeys = false;

        [$status, $on] = $this->admin('PUT', '/api/admin/payments/mode', ['live' => true]);
        self::assertSame(200, $status, json_encode($on) ?: '');
        self::assertSame([true, 'live', self::LIVE_KEY], [$on['data']['razorpay']['live'], $on['data']['razorpay']['mode'], $on['data']['razorpay']['key_id']]);
        self::assertSame(self::LIVE_KEY, $this->services()->gatewayKeys()->forProvider($this->demo)?->keyId);

        [, $off] = $this->admin('PUT', '/api/admin/payments/mode', ['live' => false]);
        self::assertSame([false, self::TEST_KEY], [$off['data']['razorpay']['live'], $off['data']['razorpay']['key_id']]);
        self::assertSame(['admin.payments_mode_changed', 'admin.payments_mode_changed'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action = 'admin.payments_mode_changed'"));
        self::assertSame(422, $this->admin('PUT', '/api/admin/payments/mode', ['live' => 'yes'])[0]);
    }

    public function testEachSetOfKeysIsManagedOnItsOwn(): void
    {
        $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::TEST_KEY, 'key_secret' => str_repeat('t', 24)]);
        $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::LIVE_KEY, 'key_secret' => str_repeat('l', 24)]);
        $this->admin('PUT', '/api/admin/payments/mode', ['live' => true]);

        self::assertSame(200, $this->admin('POST', '/api/admin/payments/razorpay/check?mode=test')[0]);
        [, $rotated] = $this->admin('POST', '/api/admin/payments/razorpay/webhook-secret?mode=test');
        self::assertIsString($rotated['data']['razorpay']['webhook_secret'] ?? null);

        [, $removed] = $this->admin('DELETE', '/api/admin/payments/razorpay?mode=test');
        self::assertNull($removed['data']['razorpay']['accounts']['test']);
        self::assertSame(self::LIVE_KEY, $removed['data']['razorpay']['accounts']['live']['key_id'], 'the keys in use stay');
        self::assertSame(422, $this->admin('DELETE', '/api/admin/payments/razorpay?mode=other')[0]);
        self::assertSame([409, 'live_keys_in_use'], $this->codeOf($this->admin('DELETE', '/api/admin/payments/razorpay?mode=live')));
    }

    public function testCustomersAreOfferedOnlinePaymentOnlyWhenTheModeInUseHasKeys(): void
    {
        Fixtures::service($this->pdo, $this->demo, ['slug' => 'thesis', 'price_minor' => 99900, 'payment_methods' => '["upi","razorpay_link"]']);
        $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::LIVE_KEY, 'key_secret' => str_repeat('l', 24)]);
        $methods = fn(): array => $this->call('GET', '/api/providers/demo')[1]['data']['services'][0]['payment_methods'];

        self::assertSame(['upi'], $methods(), 'live keys alone do nothing while the site is in test mode');
        $this->admin('PUT', '/api/admin/payments/mode', ['live' => true]);
        self::assertSame(['upi', 'razorpay_link'], $methods());
    }

    public function testATeachersOwnAccountIsUsedOnlyInItsMode(): void
    {
        $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::LIVE_KEY, 'key_secret' => str_repeat('l', 24)]);
        $own = 'rzp_test_' . str_repeat('P', 14);
        $this->admin('PUT', "/api/admin/providers/{$this->demo}/razorpay", ['key_id' => $own, 'key_secret' => str_repeat('p', 24)]);
        self::assertSame($own, $this->services()->gatewayKeys()->forProvider($this->demo)?->keyId);
        self::assertSame('test', $this->admin('GET', '/api/admin/payments')[1]['data']['overrides'][0]['mode']);

        $this->admin('PUT', '/api/admin/payments/mode', ['live' => true]);

        self::assertSame(self::LIVE_KEY, $this->services()->gatewayKeys()->forProvider($this->demo)?->keyId, 'never test keys while live');
    }

    public function testPaymentsLiveInConfigStartsLiveOnlyWithLiveKeys(): void
    {
        $this->config = ['PAYMENTS_LIVE' => '1'];
        $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::TEST_KEY, 'key_secret' => str_repeat('t', 24)]);
        self::assertFalse($this->admin('GET', '/api/admin/payments')[1]['data']['razorpay']['live'], 'test keys only: stay in test');

        $this->admin('PUT', '/api/admin/payments/razorpay', ['key_id' => self::LIVE_KEY, 'key_secret' => str_repeat('l', 24)]);
        self::assertTrue($this->admin('GET', '/api/admin/payments')[1]['data']['razorpay']['live']);
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
