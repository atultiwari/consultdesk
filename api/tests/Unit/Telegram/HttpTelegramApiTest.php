<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Telegram;

use ArrayObject;
use ConsultDesk\Telegram\HttpTelegramApi;
use ConsultDesk\Telegram\InlineKeyboard;
use ConsultDesk\Telegram\TelegramApiError;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class HttpTelegramApiTest extends TestCase
{
    private const TOKEN = '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    /** Filled by Guzzle's history middleware, which takes it by reference. */
    private mixed $history = null;

    public function testSendsMessagesAsJsonWithHtmlAndButtons(): void
    {
        $api = $this->api([new Response(200, [], '{"ok":true,"result":{"message_id":77}}')]);

        $id = $api->sendMessage('-100123', '<b>Hi</b>', InlineKeyboard::row(['✅ Confirm' => 'c:5', '❌ Reject' => 'r:5']));

        self::assertSame(77, $id);
        $request = $this->sentRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.telegram.org/bot' . self::TOKEN . '/sendMessage', (string) $request->getUri());
        self::assertSame([
            'chat_id' => '-100123',
            'text' => '<b>Hi</b>',
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => '✅ Confirm', 'callback_data' => 'c:5'],
                ['text' => '❌ Reject', 'callback_data' => 'r:5'],
            ]]],
        ], json_decode((string) $request->getBody(), true));
    }

    public function testEditingWithoutAKeyboardRemovesTheButtons(): void
    {
        $api = $this->api([new Response(200, [], '{"ok":true,"result":true}')]);

        $api->editMessage('42', 9, 'Done');

        self::assertSame(['inline_keyboard' => []], json_decode((string) $this->sentRequest()->getBody(), true)['reply_markup']);
    }

    public function testUnchangedEditsAreNotErrors(): void
    {
        $api = $this->api([new Response(400, [], '{"ok":false,"description":"Bad Request: message is not modified"}')]);

        $api->editMessage('42', 9, 'Same');
        $this->addToAssertionCount(1);
    }

    public function testApiErrorsNeverContainTheToken(): void
    {
        $api = $this->api([
            new Response(403, [], '{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}'),
            new ConnectException('cURL error 6: Could not resolve host for https://api.telegram.org/bot' . self::TOKEN . '/sendMessage', new Request('POST', 'https://api.telegram.org/bot' . self::TOKEN . '/sendMessage')),
        ]);

        foreach (['blocked by the user', 'unreachable'] as $expected) {
            try {
                $api->sendMessage('42', 'x');
                self::fail('Expected an error.');
            } catch (TelegramApiError $e) {
                self::assertStringContainsString($expected, $e->getMessage());
                self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
                self::assertStringNotContainsString(self::TOKEN, (string) $e);
            }
        }
    }

    public function testRegistersTheWebhookWithItsSecret(): void
    {
        $api = $this->api([new Response(200, [], '{"ok":true,"result":true}')]);

        $api->setWebhook('https://book.example.test/api/webhooks/telegram', 'secret-value');

        self::assertSame([
            'url' => 'https://book.example.test/api/webhooks/telegram',
            'secret_token' => 'secret-value',
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ], json_decode((string) $this->sentRequest()->getBody(), true));
    }

    private function sentRequest(): RequestInterface
    {
        $entry = $this->history instanceof ArrayObject ? ($this->history[0] ?? null) : null;
        $request = is_array($entry) ? ($entry['request'] ?? null) : null;
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function api(array $responses): HttpTelegramApi
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $history = new ArrayObject();
        $stack->push(Middleware::history($history));
        $this->history = $history;

        return new HttpTelegramApi(self::TOKEN, new Client(['handler' => $stack]));
    }
}
