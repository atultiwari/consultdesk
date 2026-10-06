<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

/**
 * The few Bot API calls ConsultDesk needs (plain REST; no SDK).
 */
interface TelegramApi
{
    /**
     * @return int the new message's id
     *
     * @throws TelegramApiError
     */
    public function sendMessage(string $chatId, string $html, ?InlineKeyboard $keyboard = null): int;

    /**
     * Replaces a message's text; with no keyboard the buttons are removed. Editing to identical
     * content is not an error.
     *
     * @throws TelegramApiError
     */
    public function editMessage(string $chatId, int $messageId, string $html, ?InlineKeyboard $keyboard = null): void;

    /**
     * Shows a short notice to the person who pressed a button.
     *
     * @throws TelegramApiError
     */
    public function answerCallback(string $callbackQueryId, string $text): void;

    /**
     * @throws TelegramApiError
     */
    public function setWebhook(string $url, string $secret): void;

    /**
     * Any other Bot API method, e.g. getMe or getWebhookInfo.
     *
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed> the "result" field (an object, or a list for getUpdates)
     *
     * @throws TelegramApiError
     */
    public function call(string $method, array $params = []): array;
}
