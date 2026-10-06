<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use RuntimeException;

/**
 * A Bot API call failed. Messages never include the bot token.
 */
final class TelegramApiError extends RuntimeException {}
