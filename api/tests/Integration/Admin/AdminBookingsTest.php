<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Integration\Support\Fixtures;

/**
 * "Now" is Monday 2026-10-05 00:00 UTC.
 */
final class AdminBookingsTest extends AdminTestCase
{
    private int $demo;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->demo = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo']);
        Fixtures::service($this->pdo, $this->demo, ['slug' => 'thesis', 'title' => 'Thesis guidance', 'payment_methods' => '["upi"]', 'questions' => '[{"id":"goal","label":"Goal","type":"text"}]']);
        Fixtures::service($this->pdo, $this->demo, ['slug' => 'intro', 'title' => 'Intro call', 'price_minor' => 0, 'payment_methods' => '["free"]', 'requires_approval' => 1]);
        $this->other = Fixtures::provider($this->pdo, ['slug' => 'other', 'name' => 'Prof. Other']);
        Fixtures::service($this->pdo, $this->other, ['slug' => 'talk', 'title' => 'Talk', 'payment_methods' => '["upi"]']);
    }

    public function testDashboardShowsWhatNeedsAttention(): void
    {
        $paid = $this->book('demo', 'thesis', '2026-10-07T04:30:00Z', utr: '412345678901');
        $this->book('demo', 'intro', '2026-10-07T08:30:00Z', method: 'free');
        $this->book('other', 'talk', '2026-10-07T04:30:00Z', utr: '412345678902');
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->admin('GET', '/api/admin/dashboard');

        self::assertSame(200, $status);
        self::assertCount(2, $body['data']['to_verify']);
        self::assertSame('412345678901', array_column($body['data']['to_verify'], 'utr', 'ref')[$paid]);
        self::assertCount(1, $body['data']['to_approve']);
        self::assertSame([], $body['data']['upcoming']);
    }

    public function testProvidersOnlySeeTheirOwnBookings(): void
    {
        $mine = $this->book('demo', 'thesis', '2026-10-07T04:30:00Z');
        $theirs = $this->book('other', 'talk', '2026-10-07T04:30:00Z');
        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');

        [, $list] = $this->admin('GET', '/api/admin/bookings');
        self::assertSame([$mine], array_column($list['data'], 'ref'));
        [, $dashboard] = $this->admin('GET', '/api/admin/dashboard');
        self::assertSame([], array_column($dashboard['data']['to_verify'], 'ref'));

        $theirsId = $this->idOf($theirs);
        self::assertSame(404, $this->admin('GET', "/api/admin/bookings/{$theirsId}")[0]);
        self::assertSame(404, $this->admin('POST', "/api/admin/bookings/{$theirsId}/cancel")[0]);
    }

    public function testListFiltersSearchesAndPaginates(): void
    {
        $refs = [];
        foreach (['04:30', '06:30', '08:30'] as $i => $time) {
            $refs[] = $this->book('demo', 'thesis', "2026-10-08T{$time}:00Z", email: "person{$i}@example.test");
        }
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [, $page] = $this->admin('GET', '/api/admin/bookings?per_page=2&page=1');
        self::assertCount(2, $page['data']);
        self::assertSame(['total' => 3, 'page' => 1, 'per_page' => 2], $page['meta']);

        [, $search] = $this->admin('GET', '/api/admin/bookings?q=person1%40');
        self::assertSame([$refs[1]], array_column($search['data'], 'ref'));
        [, $byRef] = $this->admin('GET', '/api/admin/bookings?q=' . substr($refs[2], 3));
        self::assertSame([$refs[2]], array_column($byRef['data'], 'ref'));

        [, $filtered] = $this->admin('GET', '/api/admin/bookings?status=held&provider=' . $this->other);
        self::assertSame([], $filtered['data']);
        self::assertSame([422, 'validation_failed'], [$this->admin('GET', '/api/admin/bookings?status=nonsense')[0], 'validation_failed']);
    }

    public function testDetailShowsAnswersAndHistory(): void
    {
        $ref = $this->book('demo', 'thesis', '2026-10-07T04:30:00Z', utr: '412345678901');
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->admin('GET', '/api/admin/bookings/' . $this->idOf($ref));

        self::assertSame(200, $status);
        self::assertSame('awaiting_verification', $body['data']['status']);
        self::assertSame('asha@example.test', $body['data']['customer']['email']);
        self::assertSame([['id' => 'goal', 'label' => 'Goal', 'value' => 'Feedback']], $body['data']['answers']);
        self::assertSame(['booking.held', 'booking.utr_submitted'], array_column($body['data']['history'], 'action'));
        self::assertSame(['confirm', 'reject', 'cancel'], $body['data']['actions']);
    }

    public function testStaffConfirmRejectAndCancelFromThePanel(): void
    {
        $paid = $this->book('demo', 'thesis', '2026-10-07T04:30:00Z', utr: '412345678901');
        $request = $this->book('demo', 'intro', '2026-10-07T08:30:00Z', method: 'free');
        $ownerId = $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $confirmed] = $this->admin('POST', '/api/admin/bookings/' . $this->idOf($paid) . '/confirm');
        self::assertSame(200, $status);
        self::assertSame('confirmed', $confirmed['data']['status']);
        self::assertSame(['complete', 'no-show', 'cancel'], $confirmed['data']['actions']);
        self::assertSame((string) $ownerId, self::column($this->pdo, 'SELECT confirmed_by FROM bookings WHERE ref = :r', ['r' => $paid])[0] ?? null);

        self::assertSame('rejected', $this->admin('POST', '/api/admin/bookings/' . $this->idOf($request) . '/reject')[1]['data']['status']);
        self::assertSame([409, 'invalid_transition'], $this->codeOf($this->admin('POST', '/api/admin/bookings/' . $this->idOf($request) . '/confirm')));
        self::assertSame([409, 'session_not_started'], $this->codeOf($this->admin('POST', '/api/admin/bookings/' . $this->idOf($paid) . '/complete')));
        self::assertSame(404, $this->admin('POST', '/api/admin/bookings/' . $this->idOf($paid) . '/explode')[0]);
    }

    public function testProvidersSeeWhoActedByRoleNotByEmail(): void
    {
        $ref = $this->book('demo', 'thesis', '2026-10-07T04:30:00Z', utr: '412345678901');
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->admin('POST', '/api/admin/bookings/' . $this->idOf($ref) . '/confirm');

        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');
        $history = $this->admin('GET', '/api/admin/bookings/' . $this->idOf($ref))[1]['data']['history'];

        self::assertSame([null, null, 'Owner'], array_column($history, 'actor'));
    }

    private function book(string $provider, string $service, string $start, ?string $utr = null, string $method = 'upi', string $email = 'asha@example.test'): string
    {
        [$status, $body] = $this->call('POST', '/api/bookings', [
            'provider' => $provider,
            'service' => $service,
            'start' => $start,
            'payment_method' => $method,
            'customer' => ['name' => 'Asha Placeholder', 'email' => $email, 'phone' => '+910000000000'],
            'answers' => ['goal' => 'Feedback'],
        ], '198.51.100.' . random_int(1, 250));
        self::assertSame(201, $status, json_encode($body) ?: '');
        $ref = (string) $body['data']['ref'];
        if ($utr !== null) {
            $this->call('POST', "/api/bookings/{$ref}/utr", ['token' => $body['data']['token'], 'utr' => $utr], '198.51.100.' . random_int(1, 250));
        }

        return $ref;
    }

    private function idOf(string $ref): int
    {
        return (int) (self::column($this->pdo, 'SELECT id FROM bookings WHERE ref = :ref', ['ref' => $ref])[0] ?? 0);
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
