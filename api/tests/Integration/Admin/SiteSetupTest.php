<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

final class SiteSetupTest extends AdminTestCase
{
    public function testANewSiteAsksToBeSetUpAndOffersStarterSessions(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->admin('GET', '/api/admin/setup');

        self::assertSame(200, $status);
        self::assertSame([null, false, null], [$body['data']['mode'], $body['data']['completed'], $body['data']['provider']]);
        self::assertNotEmpty($body['data']['template_sets']);
        $first = $body['data']['template_sets'][0];
        self::assertSame(['key', 'label', 'description', 'templates'], array_keys($first));
        self::assertSame(['key', 'title', 'tagline', 'audience', 'duration_min', 'price_minor', 'requires_approval'], array_keys($first['templates'][0]));
    }

    public function testAOneTeacherSiteFromStartToFinish(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        self::assertSame(200, $this->admin('PUT', '/api/admin/setup/mode', ['mode' => 'single'])[0]);
        [$status, $teacher] = $this->admin('POST', '/api/admin/setup/teacher', [
            'name' => 'Dr. Atul Tiwari',
            'title' => 'Pathologist · AI researcher',
            'timezone' => 'Asia/Kolkata',
            'upi_vpa' => 'atul.placeholder@okaxis',
        ]);
        self::assertSame(200, $status, json_encode($teacher) ?: '');
        self::assertSame('dr-atul-tiwari', $teacher['data']['provider']['slug']);

        [, $again] = $this->admin('POST', '/api/admin/setup/teacher', ['name' => 'Dr. Atul Tiwari', 'title' => 'Pathologist', 'timezone' => 'Asia/Kolkata']);
        self::assertSame($teacher['data']['provider']['id'], $again['data']['provider']['id'], 'the same teacher is updated, not duplicated');

        $template = $teacher['data']['template_sets'][0]['templates'][0];
        [$status, $sessions] = $this->admin('POST', '/api/admin/setup/sessions', ['sessions' => [
            ['key' => $template['key']],
            ['key' => $teacher['data']['template_sets'][0]['templates'][1]['key'], 'title' => 'My own title', 'duration_min' => 45, 'price_minor' => 99900],
        ]]);
        self::assertSame(200, $status, json_encode($sessions) ?: '');
        $providerId = (int) $teacher['data']['provider']['id'];
        self::assertSame([$template['title'], 'My own title'], self::column($this->pdo, 'SELECT title FROM services WHERE provider_id = :p ORDER BY sort_order, id', ['p' => $providerId]));
        self::assertSame(['45', '99900'], (self::column($this->pdo, 'SELECT duration_min FROM services WHERE title = :t UNION ALL SELECT price_minor FROM services WHERE title = :t2', ['t' => 'My own title', 't2' => 'My own title'])));
        self::assertGreaterThan(0, (int) (self::column($this->pdo, 'SELECT COUNT(*) FROM availability_rules WHERE provider_id = :p', ['p' => $providerId])[0] ?? 0), 'starter weekly hours, to edit later');

        [, $done] = $this->admin('POST', '/api/admin/setup/complete');
        self::assertTrue($done['data']['completed']);
        $site = $this->call('GET', '/api/site')[1]['data'];
        self::assertSame(['single', 'dr-atul-tiwari'], [$site['mode'], $site['single_provider']]);
    }

    public function testStarterSessionsCanBeSkippedAndUnknownOnesAreRefused(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->admin('PUT', '/api/admin/setup/mode', ['mode' => 'multi']);
        self::assertSame([409, 'teacher_first'], $this->codeOf($this->admin('POST', '/api/admin/setup/sessions', ['sessions' => []])));
        $this->admin('POST', '/api/admin/setup/teacher', ['name' => 'First Teacher', 'timezone' => 'Asia/Kolkata']);

        self::assertSame(200, $this->admin('POST', '/api/admin/setup/sessions', ['sessions' => []])[0]);
        self::assertSame(['0'], self::column($this->pdo, 'SELECT COUNT(*) FROM services'));
        self::assertSame(422, $this->admin('POST', '/api/admin/setup/sessions', ['sessions' => [['key' => 'made-up']]])[0]);
    }

    public function testAOneTeacherSiteCanGrowToSeveralButNotBack(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->admin('PUT', '/api/admin/setup/mode', ['mode' => 'single']);
        $this->admin('POST', '/api/admin/setup/teacher', ['name' => 'Only Teacher', 'timezone' => 'Asia/Kolkata']);

        [$blocked, $why] = $this->admin('POST', '/api/admin/providers', ['slug' => 'second', 'name' => 'Second Teacher', 'timezone' => 'Asia/Kolkata']);
        self::assertSame([409, 'single_teacher_site'], [$blocked, $why['error']['code']]);

        self::assertSame(200, $this->admin('PUT', '/api/admin/setup/mode', ['mode' => 'multi'])[0]);
        self::assertSame(201, $this->admin('POST', '/api/admin/providers', ['slug' => 'second', 'name' => 'Second Teacher', 'timezone' => 'Asia/Kolkata'])[0]);
        self::assertSame([409, 'several_teachers'], $this->codeOf($this->admin('PUT', '/api/admin/setup/mode', ['mode' => 'single'])));
    }

    public function testOnlyOwnersSetUpTheSite(): void
    {
        $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');

        self::assertSame(403, $this->admin('GET', '/api/admin/setup')[0]);
        self::assertSame(403, $this->admin('PUT', '/api/admin/setup/mode', ['mode' => 'single'])[0]);
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
