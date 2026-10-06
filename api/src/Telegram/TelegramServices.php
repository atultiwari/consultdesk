<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

final class TelegramServices
{
    public function __construct(
        public readonly TelegramApi $api,
        public readonly TelegramDirectory $directory,
        public readonly MessageLog $log,
    ) {}
}
