<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Http;

use ConsultDesk\Tests\Integration\Support\Fixtures;

/**
 * "My bookings": customers sign in with a link emailed to them and see every booking made with
 * that address. "Now" is Monday 2026-10-05 00:00 UTC.
 */
final class CustomerAccessTest extends ApiTestCase
{
    private string $cookie = '';

    protected function setUp(): void
    {
        parent::setUp();
        $demo = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo']);
        Fixtures::service($this->pdo, $demo, ['slug' => 'thesis', 'title' => 'Thesis guidance', 'price_minor' => 99900, 'payment_methods' => '["upi"]']);
    }

    public function testACustomerGetsALinkSignsInAndSeesOnlyTheirBookings(): void
    {
        $mine = $this->book('2026-10-07T04:30:00Z', 'asha@example.test');
        $this->book('2026-10-07T06:30:00Z', 'someone.else@example.test');

        self::assertSame([200, true], $this->linkFor('Asha@Example.test'));
        $this->services()->cronRunner()->run();
        $mail = $this->linkMail();
        self::assertSame(['asha@example.test'], $mail->to);
        self::assertSame('Your bookings', $mail->subject);
        self::assertMatchesRegularExpression('#' . preg_quote(self::APP_URL, '#') . '/my-bookings\?token=([A-Za-z0-9_-]{43})#', $mail->text);
        $token = self::tokenIn($mail->text);

        [$status, $session, $response] = $this->call('POST', '/api/my/session', ['token' => $token]);
        self::assertSame(200, $status, json_encode($session) ?: '');
        self::assertSame('asha@example.test', $session['data']['email']);
        $this->cookie = self::cookieFrom($response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('__Host-cd_customer=', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('SameSite=Strict', $response->getHeaderLine('Set-Cookie'));

        self::assertSame(404, $this->call('POST', '/api/my/session', ['token' => $token])[0], 'a link works once');

        [$status, $list] = $this->mine('GET', '/api/my/bookings');
        self::assertSame(200, $status);
        self::assertSame([$mine], array_column($list['data']['upcoming'], 'ref'));
        self::assertSame([], $list['data']['past']);
        self::assertMatchesRegularExpression('#^' . preg_quote(self::APP_URL, '#') . '/b/' . $mine . '\?t=#', $list['data']['upcoming'][0]['status_url']);
        self::assertTrue($list['data']['upcoming'][0]['can_cancel']);
    }

    public function testUnknownAddressesGetTheSameAnswerAndNoEmail(): void
    {
        self::assertSame([200, true], $this->linkFor('nobody@example.test'));
        $this->services()->cronRunner()->run();

        self::assertSame([], array_filter($this->mailer->sent, static fn($m) => $m->subject === 'Your bookings'));
    }

    public function testCustomersCanCancelOnlyUnpaidBookingsOfTheirOwn(): void
    {
        $held = $this->book('2026-10-07T04:30:00Z', 'asha@example.test');
        $paid = $this->book('2026-10-07T06:30:00Z', 'asha@example.test');
        $theirs = $this->book('2026-10-07T08:30:00Z', 'other@example.test');
        $this->pdo->exec("UPDATE bookings SET status = 'confirmed' WHERE ref = '{$paid}'");
        $this->signIn('asha@example.test');

        self::assertSame(200, $this->mine('POST', "/api/my/bookings/{$held}/cancel")[0]);
        self::assertSame(['cancelled'], self::column($this->pdo, 'SELECT status FROM bookings WHERE ref = :r', ['r' => $held]));
        self::assertSame(409, $this->mine('POST', "/api/my/bookings/{$paid}/cancel")[0], 'paid: ask the teacher');
        self::assertSame(404, $this->mine('POST', "/api/my/bookings/{$theirs}/cancel")[0]);
        self::assertSame(['customer'], self::column($this->pdo, "SELECT actor_type FROM audit_log WHERE action = 'booking.cancelled'"));
    }

    public function testWithoutASessionThereIsNothingToSee(): void
    {
        self::assertSame(401, $this->call('GET', '/api/my/bookings')[0]);
        $this->book('2026-10-07T04:30:00Z', 'asha@example.test');
        $this->signIn('asha@example.test');
        self::assertSame(200, $this->mine('POST', '/api/my/logout')[0]);
        self::assertSame(401, $this->mine('GET', '/api/my/bookings')[0]);
    }

    public function testAnExpiredLinkDoesNotWork(): void
    {
        $this->book('2026-10-07T04:30:00Z', 'asha@example.test');
        $this->linkFor('asha@example.test');
        $this->services()->cronRunner()->run();
        $token = self::tokenIn($this->linkMail()->text);

        $this->at('2026-10-05T00:16Z');

        self::assertSame(404, $this->call('POST', '/api/my/session', ['token' => $token])[0]);
    }

    private static function tokenIn(string $text): string
    {
        return preg_match('#token=([A-Za-z0-9_-]{43})#', $text, $m) === 1 ? $m[1] : self::fail('No token in the email.');
    }

    private static function cookieFrom(string $header): string
    {
        return preg_match('/^([^=]+=[^;]+)/', $header, $m) === 1 ? $m[1] : self::fail('No cookie was set.');
    }

    private function linkMail(): \ConsultDesk\Notify\Mail\EmailMessage
    {
        $links = array_values(array_filter($this->mailer->sent, static fn($m) => $m->subject === 'Your bookings'));

        return $links[count($links) - 1] ?? self::fail('No sign-in link was emailed.');
    }

    private function signIn(string $email): void
    {
        $this->linkFor($email);
        $this->services()->cronRunner()->run();
        $response = $this->call('POST', '/api/my/session', ['token' => self::tokenIn($this->linkMail()->text)])[2];
        $this->cookie = self::cookieFrom($response->getHeaderLine('Set-Cookie'));
    }

    /**
     * @return array{int, bool}
     */
    private function linkFor(string $email): array
    {
        [$status, $body] = $this->call('POST', '/api/my/link', ['email' => $email]);

        return [$status, (bool) ($body['data']['ok'] ?? false)];
    }

    /**
     * @return array{int, array<string, mixed>, mixed}
     */
    private function mine(string $method, string $uri): array
    {
        return $this->call($method, $uri, $method === 'GET' ? null : [], headers: ['Cookie' => $this->cookie]);
    }

    private function book(string $start, string $email): string
    {
        [$status, $body] = $this->call('POST', '/api/bookings', [
            'provider' => 'demo',
            'service' => 'thesis',
            'start' => $start,
            'payment_method' => 'upi',
            'customer' => ['name' => 'Asha Placeholder', 'email' => $email, 'phone' => '+91 00000-00000'],
        ], '198.51.100.' . random_int(1, 250));
        self::assertSame(201, $status, json_encode($body) ?: '');

        return (string) $body['data']['ref'];
    }
}
