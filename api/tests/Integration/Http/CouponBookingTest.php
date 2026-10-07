<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Http;

use ConsultDesk\Tests\Integration\Support\Fixtures;

/**
 * Coupons at booking time. "Now" is Monday 2026-10-05 00:00 UTC.
 */
final class CouponBookingTest extends ApiTestCase
{
    private int $demo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->demo = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo']);
        Fixtures::service($this->pdo, $this->demo, ['slug' => 'thesis', 'price_minor' => 99900, 'payment_methods' => '["upi"]']);
        Fixtures::service($this->pdo, $this->demo, ['slug' => 'intro', 'price_minor' => 0, 'payment_methods' => '["free"]']);
    }

    public function testACustomerChecksACodeThenBooksAtTheLowerPrice(): void
    {
        Fixtures::coupon($this->pdo, 'WELCOME20');

        [$status, $check] = $this->call('POST', '/api/coupons/check', ['provider' => 'demo', 'service' => 'thesis', 'code' => ' welcome20 ']);
        self::assertSame(200, $status, json_encode($check) ?: '');
        self::assertSame(['code' => 'WELCOME20', 'discount_minor' => 19900, 'total_minor' => 80000], array_intersect_key($check['data'], array_flip(['code', 'discount_minor', 'total_minor'])));
        self::assertSame('₹800', $check['data']['total_display']);

        [$status, $body] = $this->book('2026-10-07T04:30:00Z', 'welcome20');
        self::assertSame(201, $status, json_encode($body) ?: '');
        $booking = $body['data']['booking'];
        self::assertSame([80000, 'WELCOME20', 19900], [$booking['amount_minor'], $booking['coupon_code'], $booking['discount_minor']]);
        self::assertSame(['80000', '19900'], [
            self::column($this->pdo, 'SELECT amount_minor FROM bookings')[0],
            self::column($this->pdo, 'SELECT discount_minor FROM bookings')[0],
        ]);
    }

    public function testAFullDiscountMakesItAFreeBookingConfirmedAtOnce(): void
    {
        Fixtures::coupon($this->pdo, 'GUEST', ['value' => 100]);

        [$status, $body] = $this->book('2026-10-07T04:30:00Z', 'GUEST');

        self::assertSame(201, $status, json_encode($body) ?: '');
        self::assertSame(['confirmed', 0], [$body['data']['booking']['status'], $body['data']['booking']['amount_minor']]);
        self::assertSame(['free'], self::column($this->pdo, 'SELECT payment_method FROM bookings'));
    }

    public function testUsesAreLimitedAndAHoldThatLapsesGivesItsUseBack(): void
    {
        Fixtures::coupon($this->pdo, 'ONCE', ['max_uses' => 1, 'once_per_email' => 0]);

        self::assertSame(201, $this->book('2026-10-07T04:30:00Z', 'ONCE')[0]);
        [$status, $body] = $this->book('2026-10-07T06:30:00Z', 'ONCE');
        self::assertSame([422, ['coupon']], [$status, array_keys($body['error']['fields'] ?? [])]);
        self::assertSame('This code has been used up.', $body['error']['fields']['coupon']);

        $this->pdo->exec("UPDATE bookings SET status = 'expired'");
        self::assertSame(201, $this->book('2026-10-07T08:30:00Z', 'ONCE')[0]);
    }

    public function testOncePerEmail(): void
    {
        Fixtures::coupon($this->pdo, 'FIRSTTIME');

        self::assertSame(201, $this->book('2026-10-07T04:30:00Z', 'FIRSTTIME', 'same@example.test')[0]);
        [$status, $body] = $this->book('2026-10-07T06:30:00Z', 'FIRSTTIME', 'Same@Example.test');
        self::assertSame([422, 'You’ve already used this code.'], [$status, $body['error']['fields']['coupon'] ?? null]);
    }

    public function testCodesThatDontFitAreRefusedWithoutBooking(): void
    {
        $other = Fixtures::provider($this->pdo, ['slug' => 'other']);
        Fixtures::coupon($this->pdo, 'OTHERS', ['provider_id' => $other]);
        Fixtures::coupon($this->pdo, 'OLD', ['valid_until' => '2026-10-01 00:00:00']);
        Fixtures::coupon($this->pdo, 'OFF', ['active' => 0]);
        Fixtures::coupon($this->pdo, 'ANY');

        foreach (['NOPE', 'OTHERS', 'OLD', 'OFF'] as $code) {
            [$status, $body] = $this->call('POST', '/api/coupons/check', ['provider' => 'demo', 'service' => 'thesis', 'code' => $code]);
            self::assertSame([422, 'That code isn’t valid for this session.'], [$status, $body['error']['fields']['code'] ?? null], $code);
        }
        [$status, $body] = $this->call('POST', '/api/coupons/check', ['provider' => 'demo', 'service' => 'intro', 'code' => 'ANY']);
        self::assertSame([422, 'This session is already free.'], [$status, $body['error']['fields']['code'] ?? null]);

        self::assertSame(422, $this->book('2026-10-07T04:30:00Z', 'OTHERS')[0]);
        self::assertSame(['0'], self::column($this->pdo, 'SELECT COUNT(*) FROM bookings'));
    }

    /**
     * @return array{int, array<string, mixed>, mixed}
     */
    private function book(string $start, string $coupon, ?string $email = null): array
    {
        return $this->call('POST', '/api/bookings', [
            'provider' => 'demo',
            'service' => 'thesis',
            'start' => $start,
            'payment_method' => 'upi',
            'coupon' => $coupon,
            'customer' => ['name' => 'Asha Placeholder', 'email' => $email ?? 'asha' . random_int(1, 99999) . '@example.test', 'phone' => '+91 00000-00000'],
        ], '198.51.100.' . random_int(1, 250));
    }
}
