<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Integration\Support\Fixtures;

final class AdminCatalogTest extends AdminTestCase
{
    private int $demo;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->demo = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo'], openAllWeek: false);
        $this->other = Fixtures::provider($this->pdo, ['slug' => 'other', 'name' => 'Prof. Other'], openAllWeek: false);
    }

    public function testStaffCreateProvidersAndProvidersCannot(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        [$status, $body] = $this->admin('POST', '/api/admin/providers', ['slug' => 'new-teacher', 'name' => 'New Teacher', 'timezone' => 'Asia/Kolkata']);
        self::assertSame(201, $status);
        self::assertSame('new-teacher', $body['data']['slug']);
        self::assertSame(10, $body['data']['rules']['buffer_before'], 'defaults apply');
        self::assertSame([422, 'validation_failed'], $this->codeOf($this->admin('POST', '/api/admin/providers', ['slug' => 'demo', 'name' => 'Duplicate', 'timezone' => 'Asia/Kolkata'])));

        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');
        self::assertSame(403, $this->admin('POST', '/api/admin/providers', ['slug' => 'x-provider', 'name' => 'X', 'timezone' => 'UTC'])[0]);
        [, $list] = $this->admin('GET', '/api/admin/providers');
        self::assertSame(['demo'], array_column($list['data'], 'slug'));
    }

    public function testProvidersEditTheirOwnProfileUpiAndRulesOnly(): void
    {
        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');

        [$status, $body] = $this->admin('PATCH', "/api/admin/providers/{$this->demo}", [
            'title' => 'Pathologist',
            'upi_vpa' => 'demo.placeholder@okaxis',
            'notify_email' => 'Me@Example.test',
            'rules' => ['buffer_before' => 15, 'buffer_after' => 5, 'max_per_day' => null, 'min_notice_min' => 120],
        ]);
        self::assertSame(200, $status);
        self::assertSame('Pathologist', $body['data']['title']);
        self::assertSame('me@example.test', $body['data']['notify_email']);
        self::assertSame(['min_notice_min' => 120, 'horizon_days' => 30, 'buffer_before' => 15, 'buffer_after' => 5, 'slot_interval' => 5, 'max_per_day' => null], $body['data']['rules']);

        self::assertSame(
            ['upi_vpa' => ['from' => 'placeholder@upi', 'to' => 'demo.placeholder@okaxis']],
            array_intersect_key(json_decode((string) (self::column($this->pdo, "SELECT data FROM audit_log WHERE action = 'admin.provider_updated'")[0] ?? '{}'), true)['changes'] ?? [], ['upi_vpa' => 1]),
            'payout details are audited with their old and new values',
        );
        self::assertSame(403, $this->admin('PATCH', "/api/admin/providers/{$this->demo}", ['slug' => 'renamed'])[0], 'slug and active are for staff');
        self::assertSame(404, $this->admin('PATCH', "/api/admin/providers/{$this->other}", ['title' => 'x'])[0]);

        [$invalid, $errors] = $this->admin('PATCH', "/api/admin/providers/{$this->demo}", ['upi_vpa' => 'not a vpa', 'timezone' => 'Mars/Base', 'rules' => ['slot_interval' => 1]]);
        self::assertSame(422, $invalid);
        self::assertSame(['upi_vpa', 'timezone', 'rules.slot_interval'], array_keys($errors['error']['fields']));
    }

    public function testServicesWithTheQuestionBuilder(): void
    {
        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');

        [$status, $body] = $this->admin('POST', "/api/admin/providers/{$this->demo}/services", [
            'slug' => 'thesis',
            'title' => 'Thesis guidance',
            'duration_min' => 60,
            'price_minor' => 299900,
            'payment_methods' => ['upi'],
            'questions' => [
                ['id' => 'stage', 'label' => 'Stage', 'type' => 'select', 'required' => true, 'options' => ['Idea', 'Writing']],
                ['id' => 'goal', 'label' => 'Goal', 'type' => 'textarea', 'required' => true],
            ],
        ]);
        self::assertSame(201, $status);
        $serviceId = $body['data']['id'];
        self::assertSame(['stage', 'goal'], array_column($body['data']['questions'], 'id'));

        [, $free] = $this->admin('PATCH', "/api/admin/services/{$serviceId}", ['price_minor' => 0, 'payment_methods' => ['upi']]);
        self::assertSame(['free'], $free['data']['payment_methods'], 'free sessions only take "free"');

        [, $paidAgain] = $this->admin('PATCH', "/api/admin/services/{$serviceId}", ['price_minor' => 149900]);
        self::assertSame(['upi'], $paidAgain['data']['payment_methods'], 'a free session made paid again takes UPI');
        self::assertSame(422, $this->admin('PATCH', "/api/admin/services/{$serviceId}", ['price_minor' => 0, 'payment_methods' => ['bogus']])[0]);
        self::assertSame(422, $this->admin('PATCH', "/api/admin/services/{$serviceId}", ['payment_methods' => [['upi']]])[0]);

        [$bad, $errors] = $this->admin('PATCH', "/api/admin/services/{$serviceId}", [
            'questions' => [['id' => 'goal', 'label' => 'A', 'type' => 'text'], ['id' => 'goal', 'label' => 'B', 'type' => 'text']],
            'duration_min' => 0,
        ]);
        self::assertSame(422, $bad);
        self::assertSame(['duration_min', 'questions'], array_keys($errors['error']['fields']));

        self::assertSame(404, $this->admin('POST', "/api/admin/providers/{$this->other}/services", ['slug' => 'x', 'title' => 'X', 'duration_min' => 30, 'price_minor' => 0])[0]);
        [, $list] = $this->admin('GET', "/api/admin/providers/{$this->demo}/services");
        self::assertSame(['thesis'], array_column($list['data'], 'slug'));
    }

    public function testTeachersOrderTheirSessionsAndHighlightAny(): void
    {
        $a = Fixtures::service($this->pdo, $this->demo, ['slug' => 'a', 'title' => 'First', 'sort_order' => 1]);
        $b = Fixtures::service($this->pdo, $this->demo, ['slug' => 'b', 'title' => 'Second', 'sort_order' => 2]);
        $c = Fixtures::service($this->pdo, $this->demo, ['slug' => 'c', 'title' => 'Third', 'sort_order' => 3]);
        $theirs = Fixtures::service($this->pdo, $this->other, ['slug' => 'x']);
        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');

        [$status, $ordered] = $this->admin('PUT', "/api/admin/providers/{$this->demo}/services/order", ['ids' => [$c, $a, $b]]);
        self::assertSame(200, $status, json_encode($ordered) ?: '');
        self::assertSame(['Third', 'First', 'Second'], array_column($ordered['data'], 'title'));
        self::assertSame(422, $this->admin('PUT', "/api/admin/providers/{$this->demo}/services/order", ['ids' => [$c, $a]])[0], 'every session, once');
        self::assertSame(422, $this->admin('PUT', "/api/admin/providers/{$this->demo}/services/order", ['ids' => [$c, $a, $theirs]])[0]);
        self::assertSame(404, $this->admin('PUT', "/api/admin/providers/{$this->other}/services/order", ['ids' => [$theirs]])[0]);

        [, $popular] = $this->admin('PATCH', "/api/admin/services/{$a}", ['highlight' => ' Most popular ']);
        self::assertSame('Most popular', $popular['data']['highlight']);
        $this->admin('PATCH', "/api/admin/services/{$b}", ['highlight' => 'New']);
        self::assertSame(422, $this->admin('PATCH', "/api/admin/services/{$c}", ['highlight' => str_repeat('x', 25)])[0]);
        self::assertSame(422, $this->admin('PATCH', "/api/admin/services/{$c}", ['highlight' => '<b>hi</b>'])[0]);

        $public = $this->call('GET', '/api/providers/demo')[1]['data']['services'];
        self::assertSame([['Third', null], ['First', 'Most popular'], ['Second', 'New']], array_map(static fn($s) => [$s['title'], $s['highlight']], $public));

        [, $cleared] = $this->admin('PATCH', "/api/admin/services/{$a}", ['highlight' => '']);
        self::assertNull($cleared['data']['highlight']);

        [$made, $added] = $this->admin('POST', "/api/admin/providers/{$this->demo}/services", ['slug' => 'later', 'title' => 'Added later', 'duration_min' => 30, 'price_minor' => 0, 'payment_methods' => ['free']]);
        self::assertSame(201, $made, json_encode($added) ?: '');
        self::assertSame('Added later', array_column($this->call('GET', '/api/providers/demo')[1]['data']['services'], 'title')[3] ?? null, 'new sessions go last, not first');
        self::assertSame(4, $added['data']['sort_order']);
    }

    public function testWeeklyAvailabilityIsReplacedAsAWhole(): void
    {
        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');
        $own = Fixtures::service($this->pdo, $this->demo, ['slug' => 'workshop']);
        $foreign = Fixtures::service($this->pdo, $this->other, ['slug' => 'talk']);

        [$status, $body] = $this->admin('PUT', "/api/admin/providers/{$this->demo}/availability", ['rules' => [
            ['weekday' => 1, 'start' => '10:00', 'end' => '13:00'],
            ['weekday' => 6, 'start' => '09:00', 'end' => '12:00', 'service_id' => $own],
        ]]);
        self::assertSame(200, $status);
        self::assertCount(2, $body['data']);

        [$bad, $errors] = $this->admin('PUT', "/api/admin/providers/{$this->demo}/availability", ['rules' => [
            ['weekday' => 8, 'start' => '10:00', 'end' => '13:00'],
            ['weekday' => 2, 'start' => '13:00', 'end' => '10:00'],
            ['weekday' => 3, 'start' => '10:00', 'end' => '11:00', 'service_id' => $foreign],
        ]]);
        self::assertSame(422, $bad);
        self::assertSame(['rules.0', 'rules.1', 'rules.2'], array_keys($errors['error']['fields']));
        self::assertCount(2, $this->admin('GET', "/api/admin/providers/{$this->demo}/availability")[1]['data'], 'a bad save changes nothing');

        [$overlap, $overlapErrors] = $this->admin('PUT', "/api/admin/providers/{$this->demo}/availability", ['rules' => [
            ['weekday' => 1, 'start' => '10:00', 'end' => '13:00'],
            ['weekday' => 1, 'start' => '12:00', 'end' => '15:00'],
            ['weekday' => 1, 'start' => '12:00', 'end' => '15:00', 'service_id' => $own],
        ]]);
        self::assertSame(422, $overlap);
        self::assertSame(['rules.1'], array_keys($overlapErrors['error']['fields']), 'overlapping windows for the same sessions are refused');
    }

    public function testBlockedTimesForAProviderOrTheWholeOrganisation(): void
    {
        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');

        [$status, $block] = $this->admin('POST', '/api/admin/blocked', ['provider_id' => $this->demo, 'start' => '2026-10-10T00:00:00+05:30', 'end' => '2026-10-11T00:00:00+05:30', 'all_day' => true, 'reason' => 'Conference']);
        self::assertSame(201, $status);
        self::assertSame('2026-10-09T18:30:00Z', $block['data']['start']);
        self::assertSame(403, $this->admin('POST', '/api/admin/blocked', ['provider_id' => null, 'start' => '2026-10-12T00:00:00Z', 'end' => '2026-10-13T00:00:00Z'])[0], 'only staff close the whole organisation');
        self::assertSame(404, $this->admin('POST', '/api/admin/blocked', ['provider_id' => $this->other, 'start' => '2026-10-12T00:00:00Z', 'end' => '2026-10-13T00:00:00Z'])[0]);
        self::assertSame(422, $this->admin('POST', '/api/admin/blocked', ['provider_id' => $this->demo, 'start' => '2026-10-13T00:00:00Z', 'end' => '2026-10-12T00:00:00Z'])[0]);

        [, $list] = $this->admin('GET', '/api/admin/blocked');
        self::assertSame(['Conference'], array_column($list['data'], 'reason'));
        self::assertSame(200, $this->admin('DELETE', "/api/admin/blocked/{$block['data']['id']}")[0]);
        self::assertSame([], $this->admin('GET', '/api/admin/blocked')[1]['data']);

        self::assertGreaterThan(0, (int) (self::column($this->pdo, "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'admin.%'")[0] ?? 0));
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
