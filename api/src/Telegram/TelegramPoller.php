<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use Closure;
use Throwable;

/**
 * Fetches bot updates from Telegram instead of waiting for the webhook, for a computer Telegram
 * can't reach (local development). Each update goes to the same handler the webhook uses.
 * Telegram allows either a webhook or fetching, not both, so the webhook is removed first.
 */
final class TelegramPoller
{
    /**
     * @param Closure(array<string, mixed>): void $handle
     */
    public function __construct(
        private readonly TelegramApi $api,
        private readonly Closure $handle,
    ) {}

    public function takeOverFromWebhook(): void
    {
        $this->api->call('deleteWebhook');
    }

    /**
     * Handles whatever is waiting and returns the offset for the next call.
     */
    public function pollOnce(int $offset): int
    {
        $updates = $this->api->call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => ['message', 'callback_query']]);
        foreach ($updates as $update) {
            if (!is_array($update) || !is_int($update['update_id'] ?? null)) {
                continue;
            }
            $offset = max($offset, $update['update_id'] + 1);
            try {
                ($this->handle)($update);
            } catch (Throwable $e) {
                error_log(sprintf('ConsultDesk: Telegram update %d failed: %s', $update['update_id'], $e->getMessage()));
            }
        }

        return $offset;
    }
}
