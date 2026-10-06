<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

final class Keyboards
{
    public static function actions(int $bookingId, AlertKind $kind): InlineKeyboard
    {
        return InlineKeyboard::row([
            '✅ Confirm' => (new CallbackData(CallbackAction::Confirm, $bookingId, $kind))->encode(),
            '❌ Reject' => (new CallbackData(CallbackAction::AskReject, $bookingId, $kind))->encode(),
        ]);
    }

    public static function rejectPrompt(int $bookingId, AlertKind $kind): InlineKeyboard
    {
        return InlineKeyboard::row([
            'Yes, reject' => (new CallbackData(CallbackAction::Reject, $bookingId, $kind))->encode(),
            '↩ Back' => (new CallbackData(CallbackAction::Back, $bookingId, $kind))->encode(),
        ]);
    }
}
