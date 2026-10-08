<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Support;

use ConsultDesk\Telegram\InlineKeyboard;
use ConsultDesk\Telegram\TelegramApi;
use ConsultDesk\Telegram\TelegramApiError;

/**
 * Records Bot API calls. Message ids start at 100.
 */
final class FakeTelegramApi implements TelegramApi
{
    /** @var list<array{chat: string, text: string, buttons: list<string>, id: int}> */
    public array $sent = [];
    /** @var list<array{chat: string, id: int, text: string, buttons: list<string>}> */
    public array $edits = [];
    /** @var list<array{id: string, text: string}> */
    public array $answers = [];
    /** @var list<string> chats whose sends fail */
    public array $failingChats = [];
    /** @var list<string> chats whose edits fail permanently (message gone) */
    public array $failingEdits = [];
    private int $nextId = 100;

    public function sendMessage(string $chatId, string $html, ?InlineKeyboard $keyboard = null): int
    {
        if (in_array($chatId, $this->failingChats, true)) {
            throw new TelegramApiError('Telegram sendMessage failed: Forbidden: bot was blocked by the user');
        }
        $id = $this->nextId++;
        $this->sent[] = ['chat' => $chatId, 'text' => $html, 'buttons' => self::buttons($keyboard), 'id' => $id];

        return $id;
    }

    public function editMessage(string $chatId, int $messageId, string $html, ?InlineKeyboard $keyboard = null): void
    {
        if (in_array($chatId, $this->failingEdits, true)) {
            throw new TelegramApiError('Telegram editMessageText failed: Bad Request: message to edit not found', true);
        }
        $this->edits[] = ['chat' => $chatId, 'id' => $messageId, 'text' => $html, 'buttons' => self::buttons($keyboard)];
    }

    public function answerCallback(string $callbackQueryId, string $text): void
    {
        $this->answers[] = ['id' => $callbackQueryId, 'text' => $text];
    }

    /** @var list<array{url: string, secret: string}> */
    public array $webhooks = [];
    /** @var array<string, mixed>|null what getMe answers; null makes it fail as for a revoked token */
    public ?array $me = ['id' => 42, 'is_bot' => true, 'username' => 'ConsultDeskTestBot'];
    public bool $down = false;

    public function setWebhook(string $url, string $secret): void
    {
        if ($this->down) {
            throw new TelegramApiError('Telegram API unreachable (setWebhook).');
        }
        $this->webhooks[] = ['url' => $url, 'secret' => $secret];
    }

    /** @var list<array{string, array<string, mixed>}> generic calls made */
    public array $calls = [];
    /** @var list<list<array<string, mixed>>> what successive getUpdates calls return */
    public array $updates = [];

    public function call(string $method, array $params = []): array
    {
        $this->calls[] = [$method, $params];
        if ($this->down) {
            throw new TelegramApiError(sprintf('Telegram API unreachable (%s).', $method));
        }
        if ($method === 'getMe') {
            return $this->me ?? throw new TelegramApiError('Telegram getMe failed: Unauthorized');
        }
        if ($method === 'getUpdates') {
            return array_shift($this->updates) ?? [];
        }

        return [];
    }

    /**
     * @return list<string> callback data of every button
     */
    private static function buttons(?InlineKeyboard $keyboard): array
    {
        $data = [];
        foreach ($keyboard === null ? [] : $keyboard->rows as $row) {
            foreach ($row as $button) {
                $data[] = $button['callback_data'];
            }
        }

        return $data;
    }
}
