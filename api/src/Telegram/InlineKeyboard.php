<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

/**
 * Buttons under a message. callback_data is limited to 64 bytes by Telegram.
 */
final class InlineKeyboard
{
    /**
     * @param list<list<array{text: string, callback_data: string}>> $rows
     */
    private function __construct(public readonly array $rows) {}

    /**
     * @param array<string, string> $buttons label => callback data
     */
    public static function row(array $buttons): self
    {
        $row = [];
        foreach ($buttons as $text => $data) {
            $row[] = ['text' => (string) $text, 'callback_data' => $data];
        }

        return new self([$row]);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function toArray(): array
    {
        return ['inline_keyboard' => $this->rows];
    }
}
