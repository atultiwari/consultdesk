<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Integration\Support\Fixtures;

final class BookingPrefixTest extends AdminTestCase
{
    /** @var array<string, string> */
    private array $env = [];

    protected function extraEnv(): array
    {
        return [...parent::extraEnv(), ...$this->env];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $demo = Fixtures::provider($this->pdo, ['slug' => 'demo']);
        Fixtures::service($this->pdo, $demo, ['slug' => 'thesis', 'price_minor' => 99900, 'payment_methods' => '["upi"]']);
    }

    public function testStaffChooseThePrefixNewBookingsGet(): void
    {
        $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');

        [, $current] = $this->admin('GET', '/api/admin/booking-codes');
        self::assertSame(['prefix' => 'CD', 'default' => 'CD'], array_intersect_key($current['data'], ['prefix' => 1, 'default' => 1]));
        self::assertMatchesRegularExpression('/^CD-[2-9A-Z]{4}$/', $this->book());

        [$status, $saved] = $this->admin('PUT', '/api/admin/booking-codes', ['prefix' => ' vrl- ']);
        self::assertSame([200, 'VRL'], [$status, $saved['data']['prefix']]);
        self::assertMatchesRegularExpression('/^VRL-[2-9A-Z]{4}$/', $this->book());
        self::assertSame(['admin.booking_prefix_changed'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action LIKE 'admin.booking_prefix%'"));

        foreach (['V', 'TOOLONG7', 'V R', 'V-L', '9AB'] as $bad) {
            self::assertSame(422, $this->admin('PUT', '/api/admin/booking-codes', ['prefix' => $bad])[0], $bad);
        }
        [, $reset] = $this->admin('PUT', '/api/admin/booking-codes', ['prefix' => '']);
        self::assertSame('CD', $reset['data']['prefix'], 'empty goes back to the default');
    }

    public function testTheDefaultCanComeFromEnvAndTeachersCantChangeIt(): void
    {
        $this->env = ['BOOKING_PREFIX' => 'VRL'];
        self::assertMatchesRegularExpression('/^VRL-/', $this->book());

        $demo = (int) self::column($this->pdo, "SELECT id FROM providers WHERE slug = 'demo'")[0];
        $this->createUser('teacher@example.test', 'provider', $demo);
        $this->login('teacher@example.test');
        self::assertSame(403, $this->admin('PUT', '/api/admin/booking-codes', ['prefix' => 'XYZ'])[0]);
    }

    private function book(): string
    {
        static $hour = 3;
        $hour += 2;
        [$status, $body] = $this->call('POST', '/api/bookings', [
            'provider' => 'demo',
            'service' => 'thesis',
            'start' => sprintf('2026-10-07T%02d:30:00Z', $hour),
            'payment_method' => 'upi',
            'customer' => ['name' => 'Asha Placeholder', 'email' => 'asha' . random_int(1, 99999) . '@example.test', 'phone' => '+910000000000'],
        ], '198.51.100.' . random_int(1, 250));
        self::assertSame(201, $status, json_encode($body) ?: '');

        return (string) $body['data']['ref'];
    }
}
