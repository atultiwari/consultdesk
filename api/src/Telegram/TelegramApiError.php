<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use RuntimeException;

/**
 * A Bot API call failed. Messages never include the bot token.
 */
final class TelegramApiError extends RuntimeException
{
    /**
     * @param bool $permanent true when retrying cannot help (e.g. message deleted, bot blocked)
     */
    public function __construct(string $message, public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }
}
