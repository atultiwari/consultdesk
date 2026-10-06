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
    /** @var array<string, bool> chat => permanent? for chats whose edits fail */
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
        if (array_key_exists($chatId, $this->failingEdits)) {
            throw new TelegramApiError('Telegram editMessageText failed: Bad Request: message to edit not found', $this->failingEdits[$chatId]);
        }
        $this->edits[] = ['chat' => $chatId, 'id' => $messageId, 'text' => $html, 'buttons' => self::buttons($keyboard)];
    }

    public function answerCallback(string $callbackQueryId, string $text): void
    {
        $this->answers[] = ['id' => $callbackQueryId, 'text' => $text];
    }

    public function setWebhook(string $url, string $secret): void {}

    public function call(string $method, array $params = []): array
    {
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
