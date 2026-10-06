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
        self::assertSame(['key', 'title', 'tagline', 'audience', 'duration_min', 'price_minor', 'price_note', 'requires_approval'], array_keys($first['templates'][0]));
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

    public function testAnExistingSiteWithSeveralTeachersIsNeverOverwritten(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->admin('POST', '/api/admin/providers', ['slug' => 'first', 'name' => 'First Teacher', 'timezone' => 'Asia/Kolkata', 'upi_vpa' => 'first.placeholder@okaxis']);
        $this->admin('POST', '/api/admin/providers', ['slug' => 'second', 'name' => 'Second Teacher', 'timezone' => 'Asia/Kolkata']);

        self::assertNull($this->admin('GET', '/api/admin/setup')[1]['data']['provider'], 'no guessing which teacher the wizard is about');
        $this->admin('POST', '/api/admin/setup/teacher', ['name' => 'New Teacher', 'timezone' => 'Asia/Kolkata']);

        self::assertSame(['First Teacher', 'first.placeholder@okaxis'], [
            self::column($this->pdo, 'SELECT name FROM providers WHERE slug = :s', ['s' => 'first'])[0],
            self::column($this->pdo, 'SELECT upi_vpa FROM providers WHERE slug = :s', ['s' => 'first'])[0],
        ]);
        self::assertSame(['3'], self::column($this->pdo, 'SELECT COUNT(*) FROM providers'));
    }

    public function testASoleExistingTeacherIsAdoptedAndChangesToMoneyDetailsAreTraced(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->admin('POST', '/api/admin/providers', ['slug' => 'only', 'name' => 'Only Teacher', 'timezone' => 'Asia/Kolkata', 'upi_vpa' => 'old.placeholder@okaxis']);

        [, $state] = $this->admin('GET', '/api/admin/setup');
        self::assertSame('only', $state['data']['provider']['slug']);
        $this->admin('POST', '/api/admin/setup/teacher', ['name' => 'Only Teacher', 'timezone' => 'Asia/Kolkata', 'upi_vpa' => 'new.placeholder@okaxis']);

        $details = self::column($this->pdo, "SELECT data FROM audit_log WHERE action = 'admin.setup_teacher_saved'")[0];
        self::assertSame(['from' => 'old.placeholder@okaxis', 'to' => 'new.placeholder@okaxis'], json_decode((string) $details, true)['changes']['upi_vpa']);
    }

    public function testAddingTheSameStarterSessionTwiceAddsItOnce(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->admin('PUT', '/api/admin/setup/mode', ['mode' => 'single']);
        [, $state] = $this->admin('POST', '/api/admin/setup/teacher', ['name' => 'Only Teacher', 'timezone' => 'Asia/Kolkata']);
        $key = $state['data']['template_sets'][0]['templates'][1]['key'];

        $this->admin('POST', '/api/admin/setup/sessions', ['sessions' => [['key' => $key]]]);
        $this->admin('POST', '/api/admin/setup/sessions', ['sessions' => [['key' => $key], ['key' => $key]]]);

        self::assertSame(['1'], self::column($this->pdo, 'SELECT COUNT(*) FROM services'));
    }

    public function testAOneTeacherSiteCannotShowASecondTeacherAndAlwaysOpensOnItsOwn(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->admin('PUT', '/api/admin/setup/mode', ['mode' => 'multi']);
        $this->admin('POST', '/api/admin/setup/teacher', ['name' => 'Zed Teacher', 'timezone' => 'Asia/Kolkata']);
        [, $hidden] = $this->admin('POST', '/api/admin/providers', ['slug' => 'aaa-hidden', 'name' => 'Hidden Teacher', 'timezone' => 'Asia/Kolkata', 'sort_order' => -1]);
        $this->admin('PATCH', '/api/admin/providers/' . $hidden['data']['id'], ['active' => false]);
        self::assertSame(200, $this->admin('PUT', '/api/admin/setup/mode', ['mode' => 'single'])[0]);

        self::assertSame([409, 'single_teacher_site'], $this->codeOf($this->admin('PATCH', '/api/admin/providers/' . $hidden['data']['id'], ['active' => true])));
        self::assertSame('zed-teacher', $this->call('GET', '/api/site')[1]['data']['single_provider']);
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
