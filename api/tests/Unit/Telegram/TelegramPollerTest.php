<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Telegram;

use ConsultDesk\Telegram\TelegramPoller;
use ConsultDesk\Tests\Support\FakeTelegramApi;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelegramPollerTest extends TestCase
{
    public function testFetchesUpdatesHandsEachToTheBotAndMovesTheOffsetOn(): void
    {
        $api = new FakeTelegramApi();
        $api->updates = [
            [['update_id' => 7, 'message' => ['text' => '/start abc']], ['update_id' => 8, 'callback_query' => ['data' => 'c:v:1']]],
            [],
        ];
        $handled = [];
        $poller = new TelegramPoller($api, static function (array $update) use (&$handled): void {
            $handled[] = $update['update_id'];
        });

        $offset = $poller->pollOnce(0);
        self::assertSame([7, 8], $handled);
        self::assertSame(9, $offset);
        self::assertSame(['getUpdates', ['offset' => 0, 'timeout' => 0, 'allowed_updates' => ['message', 'callback_query']]], $api->calls[0]);

        self::assertSame(9, $poller->pollOnce($offset), 'nothing new, same offset');
        self::assertSame(9, $api->calls[1][1]['offset']);
    }

    public function testOneBadUpdateDoesNotStopTheRest(): void
    {
        $api = new FakeTelegramApi();
        $api->updates = [[['update_id' => 1], ['update_id' => 2]]];
        $handled = [];
        $poller = new TelegramPoller($api, static function (array $update) use (&$handled): void {
            if ($update['update_id'] === 1) {
                throw new RuntimeException('boom');
            }
            $handled[] = $update['update_id'];
        });

        self::assertSame(3, $poller->pollOnce(0));
        self::assertSame([2], $handled);
    }

    public function testStopsTheWebhookSoUpdatesCanBeFetched(): void
    {
        $api = new FakeTelegramApi();
        (new TelegramPoller($api, static fn(array $u) => null))->takeOverFromWebhook();

        self::assertSame('deleteWebhook', $api->calls[0][0]);
    }
}
