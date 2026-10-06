<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Calendar\GoogleTokens;
use ConsultDesk\Tests\Integration\Support\Fixtures;
use ConsultDesk\Tests\Support\FakeGoogleApi;
use ConsultDesk\Tests\Support\FakeTelegramApi;

final class AdminIntegrationsTest extends AdminTestCase
{
    private bool $configured = true;
    private int $demo;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->telegram = new FakeTelegramApi();
        $this->google = new FakeGoogleApi();
        $this->demo = Fixtures::provider($this->pdo, ['slug' => 'demo'], openAllWeek: false);
        $this->other = Fixtures::provider($this->pdo, ['slug' => 'other'], openAllWeek: false);
        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');
    }

    protected function extraEnv(): array
    {
        return $this->configured ? [
            ...parent::extraEnv(),
            'TELEGRAM_BOT_TOKEN' => '123456789:' . str_repeat('B', 35),
            'TELEGRAM_WEBHOOK_SECRET' => str_repeat('s', 32),
            'TELEGRAM_BOT_USERNAME' => 'ConsultDeskTestBot',
            'GOOGLE_CLIENT_ID' => 'placeholder-client.apps.googleusercontent.com',
            'GOOGLE_CLIENT_SECRET' => str_repeat('g', 24),
        ] : parent::extraEnv();
    }

    public function testShowsWhatIsConnected(): void
    {
        [$status, $body] = $this->admin('GET', "/api/admin/providers/{$this->demo}/integrations");

        self::assertSame(200, $status);
        self::assertSame(['configured' => true, 'linked' => false], $body['data']['telegram']);
        self::assertSame(['configured' => true, 'connected' => false], $body['data']['google']);
        self::assertSame(404, $this->admin('GET', "/api/admin/providers/{$this->other}/integrations")[0]);
    }

    public function testTelegramLinksForTheProviderAndForMe(): void
    {
        [$status, $body] = $this->admin('POST', "/api/admin/providers/{$this->demo}/telegram/link");
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('#^https://t\.me/ConsultDeskTestBot\?start=[A-Za-z0-9_-]+$#', $body['data']['url']);
        self::assertSame(404, $this->admin('POST', "/api/admin/providers/{$this->other}/telegram/link")[0]);

        $this->pdo->exec("UPDATE providers SET telegram_chat_id = '7770001' WHERE id = {$this->demo}");
        self::assertTrue($this->admin('GET', "/api/admin/providers/{$this->demo}/integrations")[1]['data']['telegram']['linked']);
        self::assertSame(200, $this->admin('DELETE', "/api/admin/providers/{$this->demo}/telegram")[0]);
        self::assertSame(['1'], self::column($this->pdo, "SELECT telegram_chat_id IS NULL FROM providers WHERE id = {$this->demo}"));

        [, $mine] = $this->admin('POST', '/api/admin/me/telegram/link');
        self::assertStringStartsWith('https://t.me/ConsultDeskTestBot?start=', $mine['data']['url']);
        self::assertSame(200, $this->admin('DELETE', '/api/admin/me/telegram')[0]);
    }

    public function testGoogleCalendarFromThePanel(): void
    {
        [$status, $body] = $this->admin('POST', "/api/admin/providers/{$this->demo}/google/connect", ['email' => 'Provider@Example.test']);
        self::assertSame(200, $status);
        self::assertStringStartsWith('https://accounts.example.test/auth?state=', $body['data']['url']);
        self::assertSame(['provider@example.test'], $this->google?->loginHints);
        self::assertSame(422, $this->admin('POST', "/api/admin/providers/{$this->demo}/google/connect", ['email' => 'nope'])[0]);

        $this->services()->googleConnections()->connect($this->demo, 'provider@example.test', new GoogleTokens('access-1', 'refresh-1', 3600, 'openid'), ['provider@example.test'], 'provider@example.test');
        [, $status] = $this->admin('GET', "/api/admin/providers/{$this->demo}/integrations");
        self::assertSame(['configured' => true, 'connected' => true, 'active' => true, 'account_email' => 'provider@example.test', 'busy_calendar_ids' => ['provider@example.test'], 'target_calendar_id' => 'provider@example.test'], $status['data']['google']);

        [, $calendars] = $this->admin('GET', "/api/admin/providers/{$this->demo}/google/calendars");
        self::assertContains('team@group.calendar.google.com', array_column($calendars['data'], 'id'));

        self::assertSame(422, $this->admin('PUT', "/api/admin/providers/{$this->demo}/google/calendars", ['busy' => ['unknown@example.test'], 'target' => 'provider@example.test'])[0]);
        self::assertSame(422, $this->admin('PUT', "/api/admin/providers/{$this->demo}/google/calendars", ['busy' => ['provider@example.test'], 'target' => 'team@group.calendar.google.com'])[0], 'events need a calendar you can edit');
        [$saved] = $this->admin('PUT', "/api/admin/providers/{$this->demo}/google/calendars", ['busy' => ['provider@example.test', 'team@group.calendar.google.com'], 'target' => 'provider@example.test']);
        self::assertSame(200, $saved);

        self::assertSame(200, $this->admin('DELETE', "/api/admin/providers/{$this->demo}/google")[0]);
        self::assertFalse($this->admin('GET', "/api/admin/providers/{$this->demo}/integrations")[1]['data']['google']['connected']);
    }

    public function testSaysSoWhenAnIntegrationIsNotSetUp(): void
    {
        $this->configured = false;

        [, $body] = $this->admin('GET', "/api/admin/providers/{$this->demo}/integrations");
        self::assertSame([false, false], [$body['data']['telegram']['configured'], $body['data']['google']['configured']]);
        [$status, $error] = $this->admin('POST', "/api/admin/providers/{$this->demo}/telegram/link");
        self::assertSame([409, 'not_configured'], [$status, $error['error']['code']]);
        self::assertSame(409, $this->admin('POST', "/api/admin/providers/{$this->demo}/google/connect", ['email' => 'provider@example.test'])[0]);
    }
}
