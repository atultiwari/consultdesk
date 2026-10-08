<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Support\FakeTelegramApi;

/**
 * The owner connects the Telegram bot from Integrations instead of editing config.php.
 */
final class TelegramBotSetupTest extends AdminTestCase
{
    private const TOKEN = '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private FakeTelegramApi $fake;
    /** @var array<string, string> */
    private array $config = [];

    protected function extraEnv(): array
    {
        return [...parent::extraEnv(), ...$this->config];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeTelegramApi();
        $this->telegram = $this->fake;
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
    }

    public function testTheOwnerConnectsABotByPastingItsToken(): void
    {
        [, $before] = $this->admin('GET', '/api/admin/integrations/telegram');
        self::assertSame([false, null], [$before['data']['configured'], $before['data']['source']]);
        self::assertFalse($this->admin('GET', '/api/admin/me/telegram')[1]['data']['configured']);

        [$status, $saved] = $this->admin('PUT', '/api/admin/integrations/telegram', ['bot_token' => self::TOKEN]);

        self::assertSame(200, $status, json_encode($saved) ?: '');
        self::assertSame([true, 'settings', 'ConsultDeskTestBot'], [$saved['data']['configured'], $saved['data']['source'], $saved['data']['bot_username']]);
        self::assertSame([self::APP_URL . '/api/webhooks/telegram'], array_column($this->fake->webhooks, 'url'), 'the webhook is connected for you');
        $secret = $this->fake->webhooks[0]['secret'];
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32,}$/', $secret);
        self::assertStringNotContainsString(self::TOKEN, (string) json_encode($saved));
        self::assertSame(['0'], self::column($this->pdo, 'SELECT COUNT(*) FROM settings WHERE `value` LIKE :t', ['t' => '%' . substr(self::TOKEN, 10) . '%']), 'stored encrypted');
        self::assertSame(['admin.telegram_bot_saved'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action LIKE 'admin.telegram_bot%'"));

        // The rest of the app now uses the bot: linking chats and the webhook itself.
        self::assertTrue($this->admin('GET', '/api/admin/me/telegram')[1]['data']['configured']);
        self::assertStringStartsWith('https://t.me/ConsultDeskTestBot?start=', $this->admin('POST', '/api/admin/me/telegram/link')[1]['data']['url']);
        $hook = fn(string $s): int => $this->call('POST', '/api/webhooks/telegram', ['update_id' => 1], headers: ['X-Telegram-Bot-Api-Secret-Token' => $s])[0];
        self::assertSame(200, $hook($secret));
        self::assertSame(403, $hook(str_repeat('x', 40)));
    }

    public function testBadTokensAndAnUnreachableTelegramAreExplained(): void
    {
        self::assertSame(['bot_token'], array_keys($this->admin('PUT', '/api/admin/integrations/telegram', ['bot_token' => 'nope'])[1]['error']['fields']));

        $this->fake->me = null;
        [$status, $body] = $this->admin('PUT', '/api/admin/integrations/telegram', ['bot_token' => self::TOKEN]);
        self::assertSame([422, ['bot_token']], [$status, array_keys($body['error']['fields'])]);

        $this->fake->me = ['username' => 'ConsultDeskTestBot'];
        $this->fake->down = true;
        self::assertSame([502, 'telegram_unreachable'], $this->codeOf($this->admin('PUT', '/api/admin/integrations/telegram', ['bot_token' => self::TOKEN])));
        self::assertFalse($this->admin('GET', '/api/admin/integrations/telegram')[1]['data']['configured'], 'nothing saved');
    }

    public function testTheWebhookCanBeReconnectedAndTheBotRemoved(): void
    {
        $this->admin('PUT', '/api/admin/integrations/telegram', ['bot_token' => self::TOKEN]);

        self::assertSame(200, $this->admin('POST', '/api/admin/integrations/telegram/webhook')[0]);
        self::assertCount(2, $this->fake->webhooks);
        self::assertSame($this->fake->webhooks[0]['secret'], $this->fake->webhooks[1]['secret']);

        [, $removed] = $this->admin('DELETE', '/api/admin/integrations/telegram');
        self::assertFalse($removed['data']['configured']);
        self::assertContains('deleteWebhook', array_column($this->fake->calls, 0));
        self::assertFalse($this->admin('GET', '/api/admin/me/telegram')[1]['data']['configured']);
        self::assertSame(409, $this->admin('POST', '/api/admin/integrations/telegram/webhook')[0]);
    }

    public function testReplacingTheBotUnhooksTheOldOneAndAnUnreadableBotTurnsTelegramOff(): void
    {
        $this->admin('PUT', '/api/admin/integrations/telegram', ['bot_token' => self::TOKEN]);
        $this->admin('PUT', '/api/admin/integrations/telegram', ['bot_token' => '987654321:' . str_repeat('C', 35)]);
        self::assertContains('deleteWebhook', array_column($this->fake->calls, 0));

        $this->pdo->exec("UPDATE settings SET `value` = JSON_SET(`value`, '$.token_enc', 'garbage') WHERE `key` = 'telegram_bot'");
        self::assertFalse($this->admin('GET', '/api/admin/integrations/telegram')[1]['data']['configured'], 'reconnect it, rather than every page failing');
        self::assertSame(404, $this->call('POST', '/api/webhooks/telegram', ['update_id' => 1])[0]);
    }

    public function testABotFromConfigPhpIsShownButManagedThere(): void
    {
        $this->config = [
            'TELEGRAM_BOT_TOKEN' => '123456789:' . str_repeat('B', 35),
            'TELEGRAM_WEBHOOK_SECRET' => str_repeat('s', 32),
            'TELEGRAM_BOT_USERNAME' => 'ConfigBot',
        ];

        [, $state] = $this->admin('GET', '/api/admin/integrations/telegram');
        self::assertSame([true, 'config', 'ConfigBot'], [$state['data']['configured'], $state['data']['source'], $state['data']['bot_username']]);
        self::assertSame([409, 'config_telegram'], $this->codeOf($this->admin('PUT', '/api/admin/integrations/telegram', ['bot_token' => self::TOKEN])));
        self::assertSame([409, 'config_telegram'], $this->codeOf($this->admin('DELETE', '/api/admin/integrations/telegram')));
        self::assertSame(200, $this->admin('POST', '/api/admin/integrations/telegram/webhook')[0], 'reconnecting still works');
    }

    public function testOnlyOwnersSetUpTheBot(): void
    {
        $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');

        self::assertSame(403, $this->admin('GET', '/api/admin/integrations/telegram')[0]);
        self::assertSame(403, $this->admin('PUT', '/api/admin/integrations/telegram', ['bot_token' => self::TOKEN])[0]);
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
