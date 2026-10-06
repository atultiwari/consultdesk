<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

final class HttpTelegramApi implements TelegramApi
{
    private const BASE_URL = 'https://api.telegram.org/bot';
    private const NOT_MODIFIED = 'message is not modified';

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $token,
        private readonly ClientInterface $http,
    ) {}

    public function sendMessage(string $chatId, string $html, ?InlineKeyboard $keyboard = null): int
    {
        $result = $this->call('sendMessage', $this->messageParams($chatId, $html, $keyboard));
        $id = $result['message_id'] ?? null;
        if (!is_int($id)) {
            throw new TelegramApiError('Telegram did not return a message id.');
        }

        return $id;
    }

    public function editMessage(string $chatId, int $messageId, string $html, ?InlineKeyboard $keyboard = null): void
    {
        try {
            $this->call('editMessageText', [
                ...$this->messageParams($chatId, $html, $keyboard ?? InlineKeyboard::none()),
                'message_id' => $messageId,
            ]);
        } catch (TelegramApiError $e) {
            if (!str_contains($e->getMessage(), self::NOT_MODIFIED)) {
                throw $e;
            }
        }
    }

    public function answerCallback(string $callbackQueryId, string $text): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackQueryId, 'text' => mb_substr($text, 0, 200)]);
    }

    public function setWebhook(string $url, string $secret): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    public function call(string $method, array $params = []): array
    {
        try {
            $response = $this->http->request('POST', self::BASE_URL . $this->token . '/' . $method, [
                'json' => $params,
                'http_errors' => false,
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
        } catch (GuzzleException) {
            // Guzzle messages include the request URL, which contains the token, so they are dropped.
            throw new TelegramApiError(sprintf('Telegram API unreachable (%s).', $method));
        }

        try {
            $body = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TelegramApiError(sprintf('Telegram returned HTTP %d for %s.', $response->getStatusCode(), $method));
        }
        if (!is_array($body) || ($body['ok'] ?? false) !== true) {
            $description = is_array($body) && is_string($body['description'] ?? null) ? $body['description'] : 'unknown error';
            throw new TelegramApiError(sprintf('Telegram %s failed: %s', $method, str_replace($this->token, '***', $description)));
        }

        $result = $body['result'] ?? [];

        return is_array($result) ? $result : ['value' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function messageParams(string $chatId, string $html, ?InlineKeyboard $keyboard): array
    {
        $params = ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($keyboard !== null) {
            $params['reply_markup'] = $keyboard->toArray();
        }

        return $params;
    }
}
