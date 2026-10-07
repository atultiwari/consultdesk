<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Integration\Support\Fixtures;

final class AdminCouponsTest extends AdminTestCase
{
    private int $demo;
    private int $other;
    private int $thesis;
    private int $othersSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->demo = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo']);
        $this->other = Fixtures::provider($this->pdo, ['slug' => 'other', 'name' => 'Other Teacher']);
        $this->thesis = Fixtures::service($this->pdo, $this->demo, ['slug' => 'thesis']);
        $this->othersSession = Fixtures::service($this->pdo, $this->other, ['slug' => 'theirs']);
    }

    public function testStaffMakeSiteWideCouponsAndSeeHowOftenTheyreUsed(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->admin('POST', '/api/admin/coupons', [
            'code' => ' diwali25 ', 'kind' => 'percent', 'value' => 25,
            'valid_until' => '2026-11-15T18:29:59Z', 'max_uses' => 50, 'once_per_email' => true, 'note' => 'Festival offer',
        ]);
        self::assertSame(201, $status, json_encode($body) ?: '');
        self::assertSame(['DIWALI25', null, 'percent', 25, 50, 0, true], [
            $body['data']['code'], $body['data']['provider_id'], $body['data']['kind'], $body['data']['value'],
            $body['data']['max_uses'], $body['data']['uses'], $body['data']['editable'],
        ]);
        self::assertSame(['admin.coupon_created'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action LIKE 'admin.coupon%'"));

        [$dupe, $errors] = $this->admin('POST', '/api/admin/coupons', ['code' => 'DIWALI25', 'kind' => 'amount', 'value' => 10000]);
        self::assertSame([422, ['code']], [$dupe, array_keys($errors['error']['fields'])]);

        $id = (int) $body['data']['id'];
        [, $off] = $this->admin('PATCH', "/api/admin/coupons/{$id}", ['active' => false]);
        self::assertFalse($off['data']['active']);
        self::assertSame(200, $this->admin('DELETE', "/api/admin/coupons/{$id}")[0]);
        self::assertSame([], $this->admin('GET', '/api/admin/coupons')[1]['data']);
    }

    public function testValuesAreChecked(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        $bad = [
            ['code' => 'ab', 'kind' => 'percent', 'value' => 10],
            ['code' => 'HAS SPACE', 'kind' => 'percent', 'value' => 10],
            ['code' => 'TOOMUCH', 'kind' => 'percent', 'value' => 101],
            ['code' => 'NOTHING', 'kind' => 'amount', 'value' => 0],
            ['code' => 'BACKWARDS', 'kind' => 'percent', 'value' => 10, 'valid_from' => '2026-11-01T00:00:00Z', 'valid_until' => '2026-10-01T00:00:00Z'],
            ['code' => 'NOSUCH', 'kind' => 'percent', 'value' => 10, 'service_ids' => [999999]],
        ];
        foreach ($bad as $coupon) {
            self::assertSame(422, $this->admin('POST', '/api/admin/coupons', $coupon)[0], $coupon['code']);
        }
    }

    public function testTeachersManageOnlyTheirOwnCouponsForTheirOwnSessions(): void
    {
        $this->createUser('demo@example.test', 'provider', $this->demo);
        Fixtures::coupon($this->pdo, 'SITEWIDE');
        Fixtures::coupon($this->pdo, 'THEIRS', ['provider_id' => $this->other]);
        $this->login('demo@example.test');

        [$status, $mine] = $this->admin('POST', '/api/admin/coupons', ['code' => 'MYSTUDENTS', 'kind' => 'amount', 'value' => 20000, 'provider_id' => $this->other, 'service_ids' => [$this->thesis]]);
        self::assertSame(201, $status, json_encode($mine) ?: '');
        self::assertSame([$this->demo, [$this->thesis]], [$mine['data']['provider_id'], $mine['data']['service_ids']], 'always their own, whatever was sent');

        self::assertSame(422, $this->admin('POST', '/api/admin/coupons', ['code' => 'SNEAKY', 'kind' => 'percent', 'value' => 10, 'service_ids' => [$this->othersSession]])[0]);

        $list = $this->admin('GET', '/api/admin/coupons')[1]['data'];
        self::assertSame(['MYSTUDENTS' => true, 'SITEWIDE' => false], array_column($list, 'editable', 'code'), 'site-wide ones are shown read-only; other teachers’ are hidden');

        $siteWide = (int) self::column($this->pdo, "SELECT id FROM coupons WHERE code = 'SITEWIDE'")[0];
        $theirs = (int) self::column($this->pdo, "SELECT id FROM coupons WHERE code = 'THEIRS'")[0];
        self::assertSame(403, $this->admin('PATCH', "/api/admin/coupons/{$siteWide}", ['active' => false])[0]);
        self::assertSame(404, $this->admin('DELETE', "/api/admin/coupons/{$theirs}")[0]);
    }
}
