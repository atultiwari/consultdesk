<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Http;

use ConsultDesk\Tests\Integration\Support\Fixtures;

/**
 * "Now" is Monday 2026-10-05 00:00 UTC. Bookings are for Wednesday 7 October at 10:00 IST (04:30 UTC).
 */
final class PublicApiTest extends ApiTestCase
{
    private const QUESTIONS = '[{"id": "goal", "label": "What do you want to walk away with?", "type": "textarea", "required": true}]';
    private const START = '2026-10-07T04:30:00Z';

    private int $providerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->providerId = Fixtures::provider($this->pdo, [
            'slug' => 'demo', 'name' => 'Dr. Demo', 'title' => 'Pathologist', 'sort_order' => 2,
            'whatsapp' => '+910000000000', 'notify_email' => 'provider@example.test',
        ]);
        Fixtures::service($this->pdo, $this->providerId, ['slug' => 'thesis', 'title' => 'Thesis guidance', 'questions' => self::QUESTIONS, 'payment_methods' => '["upi","razorpay_link"]']);
        Fixtures::service($this->pdo, $this->providerId, ['slug' => 'intro', 'title' => 'Intro call', 'duration_min' => 15, 'price_minor' => 0, 'payment_methods' => '["free"]']);
        Fixtures::service($this->pdo, $this->providerId, ['slug' => 'hidden', 'active' => 0]);
        Fixtures::provider($this->pdo, ['slug' => 'first', 'name' => 'First Listed', 'sort_order' => 1]);
        Fixtures::provider($this->pdo, ['slug' => 'inactive', 'active' => 0]);
    }

    public function testSiteSettingsHaveSafeDefaults(): void
    {
        [$status, $body] = $this->call('GET', '/api/site');

        self::assertSame(200, $status);
        self::assertSame(['org_name' => 'ConsultDesk', 'preset' => 'neutral', 'accent' => null, 'accent_2' => null, 'logo_url' => null, 'mode' => null, 'single_provider' => null], $body['data']);
    }

    public function testSiteSettingsAreReadAndSanitised(): void
    {
        $this->pdo->exec('DELETE FROM providers WHERE slug <> \'demo\'');
        $this->pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('site', ?)")->execute([json_encode([
            'org_name' => 'Dr. Demo Bookings',
            'preset' => 'he',
            'accent' => '#40297A',
            'accent_2' => 'red; background:url(x)',
            'logo_url' => 'javascript:alert(1)',
        ])]);

        [, $body] = $this->call('GET', '/api/site');

        self::assertSame('Dr. Demo Bookings', $body['data']['org_name']);
        self::assertSame('he', $body['data']['preset']);
        self::assertSame('#40297a', $body['data']['accent']);
        self::assertNull($body['data']['accent_2'], 'only hex colours are accepted');
        self::assertNull($body['data']['logo_url'], 'only http(s) or site-relative logos');
        self::assertSame('demo', $body['data']['single_provider'], 'lets the home page skip straight to the only provider');
    }

    public function testListsActiveProvidersWithoutPrivateFields(): void
    {
        [$status, $body] = $this->call('GET', '/api/providers');

        self::assertSame(200, $status);
        self::assertSame(['first', 'demo'], array_column($body['data'], 'slug'));
        $demo = $body['data'][1];
        self::assertSame(['slug', 'name', 'title', 'bio', 'photo_url', 'timezone'], array_keys($demo));
        self::assertSame('Pathologist', $demo['title']);
    }

    public function testShowsAProviderWithBookableServicesAndQuestions(): void
    {
        [$status, $body] = $this->call('GET', '/api/providers/demo');

        self::assertSame(200, $status);
        self::assertSame('Dr. Demo', $body['data']['provider']['name']);
        self::assertSame(['thesis', 'intro'], array_column($body['data']['services'], 'slug'));
        $thesis = $body['data']['services'][0];
        self::assertSame(60, $thesis['duration_minutes']);
        self::assertSame('₹1,499', $thesis['price_display']);
        self::assertSame(['upi'], $thesis['payment_methods'], 'Razorpay links are not offered until Phase 7');
        self::assertSame('goal', $thesis['questions'][0]['id']);
        self::assertSame(['free'], $body['data']['services'][1]['payment_methods']);

        self::assertSame([404, 'not_found'], $this->errorOf('GET', '/api/providers/inactive'));
        self::assertSame([404, 'not_found'], $this->errorOf('GET', '/api/providers/nobody'));
    }

    public function testListsSlotsInUtcForADateRange(): void
    {
        [$status, $body] = $this->call('GET', '/api/providers/demo/services/thesis/slots?from=2026-10-07&to=2026-10-07');

        self::assertSame(200, $status);
        self::assertSame('Asia/Kolkata', $body['data']['timezone']);
        $starts = array_column($body['data']['slots'], 'start');
        self::assertContains(self::START, $starts);
        self::assertSame('2026-10-07T05:30:00Z', $body['data']['slots'][array_search(self::START, $starts, true)]['end']);

        self::assertSame([422, 'validation_failed'], $this->errorOf('GET', '/api/providers/demo/services/thesis/slots?from=2026-13-01'));
        self::assertSame([422, 'validation_failed'], $this->errorOf('GET', '/api/providers/demo/services/thesis/slots?from=2026-10-01&to=2027-01-01'));
        self::assertSame([404, 'not_found'], $this->errorOf('GET', '/api/providers/demo/services/hidden/slots'));
    }

    public function testDefaultSlotRangeIsTwoWeeksFromTodayInTheProvidersTimezone(): void
    {
        [, $body] = $this->call('GET', '/api/providers/demo/services/thesis/slots');

        $days = array_values(array_unique(array_map(static fn(string $s): string => substr($s, 0, 10), array_column($body['data']['slots'], 'start'))));
        sort($days);
        self::assertSame('2026-10-06', $days[0] ?? null, 'the first 24 hours are inside the minimum notice');
        self::assertSame('2026-10-18', $days[count($days) - 1] ?? null);
        self::assertSame('2026-10-05', $body['data']['from']);
        self::assertSame('2026-10-18', $body['data']['to']);
    }

    public function testBookingWithUpiHoldsTheSlotAndReturnsPaymentInstructions(): void
    {
        [$status, $body] = $this->call('POST', '/api/bookings', $this->booking());

        self::assertSame(201, $status);
        $data = $body['data'];
        self::assertMatchesRegularExpression('/^CD-[2-9A-Z]{4}$/', $data['ref']);
        self::assertSame(self::APP_URL . "/b/{$data['ref']}?t={$data['token']}", $data['status_url']);
        self::assertSame('held', $data['booking']['status']);
        self::assertSame('2026-10-05T00:30:00Z', $data['booking']['hold_expires_at']);
        self::assertSame('placeholder@upi', $data['booking']['payment']['vpa']);
        self::assertStringStartsWith('upi://pay?pa=placeholder%40upi', $data['booking']['payment']['upi_uri']);
        self::assertTrue($data['booking']['payment']['can_submit_utr']);

        self::assertSame([409, 'slot_unavailable'], $this->errorOf('POST', '/api/bookings', $this->booking(), ip: '198.51.100.1'));
    }

    public function testFreeServiceWithoutApprovalIsConfirmedImmediately(): void
    {
        [$status, $body] = $this->call('POST', '/api/bookings', $this->booking(['service' => 'intro', 'payment_method' => 'free', 'answers' => []]));

        self::assertSame(201, $status);
        self::assertSame('confirmed', $body['data']['booking']['status']);
        self::assertNull($body['data']['booking']['payment']);
    }

    public function testBookingValidationReportsEveryBadField(): void
    {
        $bad = $this->booking([
            'start' => '2026-10-07 10:00',
            'customer' => ['name' => '', 'email' => 'nope', 'phone' => '+910000000000'],
            'answers' => ['goal' => ''],
        ]);

        [$status, $body] = $this->call('POST', '/api/bookings', $bad);

        self::assertSame(422, $status);
        self::assertSame('validation_failed', $body['error']['code']);
        self::assertSame(['start', 'customer.name', 'customer.email', 'answers.goal'], array_keys($body['error']['fields']));
    }

    public function testRejectsUnavailablePaymentMethodsHoneypotsAndNonJsonBodies(): void
    {
        self::assertSame([422, 'payment_method_not_allowed'], $this->errorOf('POST', '/api/bookings', $this->booking(['payment_method' => 'razorpay_link'])));
        self::assertSame([400, 'bad_request'], $this->errorOf('POST', '/api/bookings', $this->booking(['website' => 'http://spam.example'])));

        [$status] = $this->call('POST', '/api/bookings', null, headers: ['Content-Type' => 'application/x-www-form-urlencoded']);
        self::assertSame(415, $status);
    }

    public function testStatusPageNeedsTheExactToken(): void
    {
        [, $created] = $this->call('POST', '/api/bookings', $this->booking());
        $ref = $created['data']['ref'];
        $token = $created['data']['token'];

        [$status, $body] = $this->call('GET', "/api/bookings/{$ref}?t={$token}");
        self::assertSame(200, $status);
        self::assertSame($ref, $body['data']['ref']);
        self::assertSame('Asha Placeholder', $body['data']['customer_name']);
        self::assertSame('Thesis guidance', $body['data']['service']['title']);
        self::assertArrayNotHasKey('customer_email', $body['data']);

        self::assertSame([404, 'not_found'], $this->errorOf('GET', "/api/bookings/{$ref}?t=wrong"));
        self::assertSame([404, 'not_found'], $this->errorOf('GET', "/api/bookings/{$ref}"));
        self::assertSame([404, 'not_found'], $this->errorOf('GET', "/api/bookings/CD-ZZZZ?t={$token}"));
    }

    public function testALapsedHoldShowsAsExpiredWithoutPaymentDetails(): void
    {
        [, $created] = $this->call('POST', '/api/bookings', $this->booking());

        $this->at('2026-10-05T01:00Z');
        [, $body] = $this->call('GET', "/api/bookings/{$created['data']['ref']}?t={$created['data']['token']}");

        self::assertSame('expired', $body['data']['status']);
        self::assertNull($body['data']['payment']);
    }

    public function testSubmittingAUtr(): void
    {
        [, $created] = $this->call('POST', '/api/bookings', $this->booking());
        $ref = $created['data']['ref'];
        $token = $created['data']['token'];

        self::assertSame([422, 'validation_failed'], $this->errorOf('POST', "/api/bookings/{$ref}/utr", ['token' => $token, 'utr' => '123']));
        self::assertSame([404, 'not_found'], $this->errorOf('POST', "/api/bookings/{$ref}/utr", ['token' => 'wrong', 'utr' => '412345678901']));

        [$status, $body] = $this->call('POST', "/api/bookings/{$ref}/utr", ['token' => $token, 'utr' => '4123 4567 8901']);
        self::assertSame(200, $status);
        self::assertSame('awaiting_verification', $body['data']['status']);
        self::assertSame('412345678901', $body['data']['utr']);
        self::assertFalse($body['data']['payment']['can_submit_utr']);

        [, $other] = $this->call('POST', '/api/bookings', $this->booking(['start' => '2026-10-07T08:30:00Z']), ip: '198.51.100.2');
        self::assertSame(
            [409, 'duplicate_utr'],
            $this->errorOf('POST', "/api/bookings/{$other['data']['ref']}/utr", ['token' => $other['data']['token'], 'utr' => '412345678901']),
        );
    }

    public function testBookingIsRateLimitedPerClientIp(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->call('POST', '/api/bookings', $this->booking(['customer' => ['name' => '', 'email' => 'x', 'phone' => '1']]));
        }

        [$status, $body, $response] = $this->call('POST', '/api/bookings', $this->booking());
        self::assertSame(429, $status);
        self::assertSame('rate_limited', $body['error']['code']);
        self::assertGreaterThan(0, (int) $response->getHeaderLine('Retry-After'));

        [$otherIp] = $this->call('POST', '/api/bookings', $this->booking(), ip: '198.51.100.9');
        self::assertSame(201, $otherIp);
    }

    public function testResponsesCarrySecurityHeaders(): void
    {
        [, , $response] = $this->call('GET', '/api/providers');

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testUnknownRoutesAndMethodsUseTheEnvelope(): void
    {
        self::assertSame([404, 'not_found'], $this->errorOf('GET', '/api/nope'));
        self::assertSame([405, 'method_not_allowed'], $this->errorOf('DELETE', '/api/providers'));
    }

    public function testStatusPageStillWorksIfTheProviderRemovesTheirUpiId(): void
    {
        [, $created] = $this->call('POST', '/api/bookings', $this->booking());
        $this->pdo->exec("UPDATE providers SET upi_vpa = NULL WHERE id = {$this->providerId}");

        [$status, $body] = $this->call('GET', "/api/bookings/{$created['data']['ref']}?t={$created['data']['token']}");

        self::assertSame(200, $status);
        self::assertFalse($body['data']['payment']['available']);
        self::assertTrue($body['data']['payment']['can_submit_utr'], 'a customer who already paid can still send the UTR');
        self::assertArrayNotHasKey('upi_uri', $body['data']['payment']);
    }

    public function testSlotRangeErrorsPointAtTheRightField(): void
    {
        [, $inverted] = $this->call('GET', '/api/providers/demo/services/thesis/slots?from=2026-10-10&to=2026-10-08');
        self::assertSame(['to'], array_keys($inverted['error']['fields']));
        self::assertStringContainsString('on or after', $inverted['error']['fields']['to']);

        [, $long] = $this->call('GET', '/api/providers/demo/services/thesis/slots?from=2026-10-01&to=2027-01-01');
        self::assertStringContainsString('62 days', $long['error']['fields']['to']);
    }

    public function testCustomerNamesCannotCarryLinksOrMarkup(): void
    {
        foreach (['Visit https://phish.example', 'www.phish.example', 'Asha <b>', "Asha\u{0007}"] as $name) {
            [$status, $body] = $this->call('POST', '/api/bookings', $this->booking(['customer' => ['name' => $name, 'email' => 'asha@example.test', 'phone' => '+910000000000']]));
            self::assertSame(422, $status, $name);
            self::assertArrayHasKey('customer.name', $body['error']['fields'], $name);
        }
    }

    public function testBookingsAreAlsoRateLimitedPerEmailAddress(): void
    {
        $starts = ['2026-10-07T04:30:00Z', '2026-10-07T06:30:00Z', '2026-10-08T04:30:00Z', '2026-10-08T06:30:00Z', '2026-10-09T04:30:00Z', '2026-10-09T06:30:00Z'];
        $statuses = [];
        foreach ($starts as $i => $start) {
            [$statuses[]] = $this->call('POST', '/api/bookings', $this->booking(['start' => $start, 'customer' => [
                'name' => 'Victim Placeholder', 'email' => 'Victim@Example.test', 'phone' => '+910000000000',
            ]]), ip: "198.51.100.{$i}");
        }

        self::assertSame([201, 201, 201, 409, 409, 429], $statuses, 'three open holds per email, then the per-email limit');
    }

    public function testRejectsOversizedBodiesBeforeReadingThem(): void
    {
        [$status] = $this->call('POST', '/api/bookings', $this->booking(), headers: ['Content-Length' => (string) (1024 * 1024)]);

        self::assertSame(413, $status);
    }

    public function testMethodNotAllowedListsTheAllowedMethods(): void
    {
        [, , $response] = $this->call('DELETE', '/api/providers');

        self::assertSame('GET', $response->getHeaderLine('Allow'));
    }

    public function testCronKeyCanBeSentAsAHeader(): void
    {
        [$status] = $this->call('GET', '/api/cron', headers: ['X-Cron-Key' => self::CRON_KEY]);

        self::assertSame(200, $status);
    }

    public function testCronRequiresTheKeyAndRunsTheQueue(): void
    {
        [, $created] = $this->call('POST', '/api/bookings', $this->booking());

        self::assertSame([403, 'forbidden'], $this->errorOf('GET', '/api/cron?key=wrong'));

        $this->at('2026-10-05T01:00Z');
        [$status, $body] = $this->call('GET', '/api/cron?key=' . self::CRON_KEY);

        self::assertSame(200, $status);
        self::assertSame(1, $body['data']['expired']);
        // Cron first ran after the hold lapsed, so the stale payment email is skipped.
        self::assertSame(["Booking expired: {$created['data']['ref']}"], $this->mailer->subjects());
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array{int, string}
     */
    private function errorOf(string $method, string $uri, ?array $json = null, string $ip = '203.0.113.7'): array
    {
        [$status, $body] = $this->call($method, $uri, $json, $ip);
        self::assertFalse($body['success'] ?? true, 'error responses use the envelope');

        return [$status, (string) ($body['error']['code'] ?? '')];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function booking(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'demo',
            'service' => 'thesis',
            'start' => self::START,
            'payment_method' => 'upi',
            'customer' => ['name' => 'Asha Placeholder', 'email' => 'asha@example.test', 'phone' => '+910000000000', 'timezone' => 'Asia/Kolkata'],
            'answers' => ['goal' => 'Feedback on my thesis'],
            'website' => '',
        ], $overrides);
    }
}
