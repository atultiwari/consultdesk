<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Http;

use ConsultDesk\Admin\AdminUser;
use ConsultDesk\Admin\Role;
use ConsultDesk\Tests\Integration\Support\Fixtures;
use ConsultDesk\Tests\Support\FakeRazorpayApi;

/**
 * "Now" is Monday 2026-10-05 00:00 UTC. Placeholder Razorpay keys only.
 */
final class RazorpayBookingTest extends ApiTestCase
{
    private string $orgKey;
    private string $orgSecret;
    private string $webhookSecret;
    private int $demo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->razorpay = new FakeRazorpayApi();
        $this->orgKey = 'rzp_test_' . str_repeat('O', 14);
        $this->orgSecret = bin2hex(random_bytes(12));
        $this->webhookSecret = bin2hex(random_bytes(16));
        $this->demo = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo']);
        Fixtures::service($this->pdo, $this->demo, ['slug' => 'thesis', 'title' => 'Thesis guidance', 'price_minor' => 299900, 'payment_methods' => '["upi","razorpay_link"]']);
        $this->services()->gatewayKeys()->save(null, $this->orgKey, $this->orgSecret, $this->webhookSecret);
        Fixtures::user($this->pdo, 'owner', null, 'owner@example.test'); // receives staff emails
    }

    public function testCustomersPayOnlineAndTheWebhookConfirms(): void
    {
        self::assertSame(['upi', 'razorpay_link'], $this->call('GET', '/api/providers/demo')[1]['data']['services'][0]['payment_methods']);

        [$ref, $booking] = $this->book();
        self::assertSame('held', $booking['status']);
        self::assertSame(['method' => 'razorpay_link', 'pay_url' => 'https://rzp.example.test/plink_test000001'], array_intersect_key($booking['payment'], ['method' => 1, 'pay_url' => 1]));
        $sent = $this->razorpay?->created[0] ?? self::fail('No payment link was made.');
        self::assertSame([$this->orgKey, 299900, $ref], [$sent['key'], $sent['request']->amountMinor, $sent['request']->reference]);
        self::assertSame('2026-10-05T00:30:00+00:00', $sent['request']->expireBy->format(DATE_ATOM), 'the link dies with the hold');
        self::assertSame(self::APP_URL . "/b/{$ref}?paid=1", $sent['request']->callbackUrl, 'no status token is given to Razorpay');

        self::assertSame(400, $this->webhook($this->paidEvent($ref, 'plink_test000001'), 'not-the-signature')[0]);
        self::assertSame(200, $this->webhook($this->paidEvent($ref, 'plink_test000001'))[0]);
        self::assertSame(['confirmed', 'pay_test1'], [$this->statusOf($ref), self::column($this->pdo, 'SELECT gateway_payment_id FROM bookings WHERE ref = :ref', ['ref' => $ref])[0] ?? null]);
        self::assertSame(['webhook'], self::column($this->pdo, "SELECT actor_type FROM audit_log WHERE action = 'booking.confirmed'"));
        $bookingId = (int) (self::column($this->pdo, 'SELECT id FROM bookings WHERE ref = :ref', ['ref' => $ref])[0] ?? 0);
        self::assertSame('pay_test1', (new \ConsultDesk\Admin\AdminBookings($this->pdo))->find($bookingId, new AdminUser(1, 'owner@example.test', null, Role::Owner, null), new \DateTimeImmutable('2026-10-05T00:10Z'))['gateway_payment_id'] ?? null);

        self::assertSame(200, $this->webhook($this->paidEvent($ref, 'plink_test000001'))[0], 'Razorpay retries are harmless');
        self::assertSame(['1'], self::column($this->pdo, 'SELECT COUNT(*) FROM payment_events'));
        $this->services()->cronRunner()->run();
        self::assertContains("Booking confirmed: {$ref}", array_map(static fn($m) => $m->subject, $this->mailer->sent));
    }

    public function testTheSignedReturnConfirmsWithoutWaitingForTheWebhook(): void
    {
        [$ref] = $this->book();
        $params = [
            'razorpay_payment_id' => 'pay_test9',
            'razorpay_payment_link_id' => 'plink_test000001',
            'razorpay_payment_link_reference_id' => $ref,
            'razorpay_payment_link_status' => 'paid',
        ];
        $signature = hash_hmac('sha256', implode('|', [$params['razorpay_payment_link_id'], $ref, 'paid', 'pay_test9']), $this->orgSecret);

        self::assertSame(400, $this->call('POST', "/api/bookings/{$ref}/razorpay/return", [...$params, 'razorpay_signature' => str_repeat('0', 64)])[0]);
        [$status, $body] = $this->call('POST', "/api/bookings/{$ref}/razorpay/return", [...$params, 'razorpay_signature' => $signature]);
        self::assertSame([200, 'confirmed'], [$status, $body['data']['status']]);
        self::assertArrayNotHasKey('customer_name', $body['data'], 'the return page learns no more than the outcome');
    }

    public function testAPaymentAfterTheHoldEndedIsFlaggedForARefund(): void
    {
        [$ref] = $this->book();
        $this->at('2026-10-05T00:31Z');

        self::assertSame(200, $this->webhook($this->paidEvent($ref, 'plink_test000001'))[0]);
        self::assertNotSame('confirmed', $this->statusOf($ref));
        self::assertSame(['booking.paid_late'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action = 'booking.paid_late'"));

        $this->services()->cronRunner()->run();
        $subjects = array_map(static fn($m) => $m->subject, $this->mailer->sent);
        self::assertContains("Refund needed: {$ref}", $subjects);
        self::assertContains("Payment received after your hold ended: {$ref}", $subjects);
    }

    public function testLapsedHoldsCancelTheirLink(): void
    {
        $this->book();
        $this->at('2026-10-05T00:31Z');
        $this->services()->cronRunner()->run();
        $this->services()->cronRunner()->run();

        self::assertSame([['key' => $this->orgKey, 'id' => 'plink_test000001']], $this->razorpay?->cancelled);
    }

    public function testWrongAmountsAndUnknownLinksAreRecordedButIgnored(): void
    {
        [$ref] = $this->book();

        self::assertSame(200, $this->webhook($this->paidEvent($ref, 'plink_test000001', amount: 100))[0]);
        self::assertSame(200, $this->webhook($this->paidEvent($ref, 'plink_unknown', eventId: 'evt_2'))[0]);
        self::assertSame('held', $this->statusOf($ref));
        self::assertSame(['2'], self::column($this->pdo, 'SELECT COUNT(*) FROM payment_events'));
    }

    public function testTheOwnerSwitchesAndATeachersOwnAccount(): void
    {
        $this->services()->settings()->put('payments', ['upi_enabled' => true, 'razorpay_enabled' => false]);
        self::assertSame(['upi'], $this->call('GET', '/api/providers/demo')[1]['data']['services'][0]['payment_methods']);
        self::assertSame(422, $this->call('POST', '/api/bookings', $this->bookingBody('razorpay_link'))[0]);

        $this->services()->settings()->put('payments', ['upi_enabled' => false, 'razorpay_enabled' => true]);
        self::assertSame(['razorpay_link'], $this->call('GET', '/api/providers/demo')[1]['data']['services'][0]['payment_methods']);

        $teacherKey = 'rzp_test_' . str_repeat('T', 14);
        $this->services()->gatewayKeys()->save($this->demo, $teacherKey, bin2hex(random_bytes(12)), bin2hex(random_bytes(16)));
        $this->book();
        self::assertSame($teacherKey, $this->razorpay?->created[0]['key'], 'paid into the teacher’s own account');
    }

    public function testARazorpayOutageLeavesTheBookingAndTheLinkCanBeMadeLater(): void
    {
        $fake = new FakeRazorpayApi();
        $fake->down = true;
        $this->razorpay = $fake;
        [$status, $body] = $this->call('POST', '/api/bookings', $this->bookingBody('razorpay_link'));
        self::assertSame(201, $status);
        self::assertNull($body['data']['booking']['payment']['pay_url']);

        $fake->down = false;
        [$retry, $link] = $this->call('POST', "/api/bookings/{$body['data']['ref']}/razorpay", ['token' => $body['data']['token']]);
        self::assertSame([200, 'https://rzp.example.test/plink_test000001'], [$retry, $link['data']['payment']['pay_url']]);
        self::assertSame(404, $this->call('POST', "/api/bookings/{$body['data']['ref']}/razorpay", ['token' => 'wrong'])[0]);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function book(): array
    {
        [$status, $body] = $this->call('POST', '/api/bookings', $this->bookingBody('razorpay_link'), '198.51.100.' . random_int(1, 250));
        self::assertSame(201, $status, json_encode($body) ?: '');

        return [(string) $body['data']['ref'], $body['data']['booking']];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingBody(string $method): array
    {
        return [
            'provider' => 'demo',
            'service' => 'thesis',
            'start' => '2026-10-07T04:30:00Z',
            'payment_method' => $method,
            'customer' => ['name' => 'Asha Placeholder', 'email' => 'asha' . random_int(1, 99999) . '@example.test', 'phone' => '+910000000000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paidEvent(string $ref, string $linkId, int $amount = 299900, string $eventId = 'evt_1'): array
    {
        return [
            '_event_id' => $eventId,
            'entity' => 'event',
            'event' => 'payment_link.paid',
            'contains' => ['payment_link', 'payment'],
            'payload' => [
                'payment_link' => ['entity' => ['id' => $linkId, 'reference_id' => $ref, 'status' => 'paid', 'amount' => 299900, 'amount_paid' => $amount, 'currency' => 'INR']],
                'payment' => ['entity' => ['id' => 'pay_test1', 'amount' => $amount, 'currency' => 'INR', 'status' => 'captured']],
            ],
            'created_at' => strtotime('2026-10-05T00:05:00Z'),
        ];
    }

    /**
     * Posts the event exactly as Razorpay does: raw JSON, signed with the webhook secret.
     *
     * @param array<string, mixed> $event
     *
     * @return array{int, array<string, mixed>, mixed}
     */
    private function webhook(array $event, ?string $signature = null): array
    {
        $eventId = (string) $event['_event_id'];
        unset($event['_event_id']);
        $raw = json_encode($event, JSON_THROW_ON_ERROR);

        return $this->callRaw('POST', '/api/webhooks/razorpay', $raw, [
            'Content-Type' => 'application/json',
            'X-Razorpay-Signature' => $signature ?? hash_hmac('sha256', $raw, $this->webhookSecret),
            'X-Razorpay-Event-Id' => $eventId,
        ]);
    }

    private function statusOf(string $ref): string
    {
        return (string) (self::column($this->pdo, 'SELECT status FROM bookings WHERE ref = :ref', ['ref' => $ref])[0] ?? '');
    }
}
