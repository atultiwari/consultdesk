<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Domain\DomainError;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Db;

/**
 * Handles webhook updates: button presses on alerts, and /start <code> and /stop to link chats.
 * Callers must already have checked the webhook secret.
 */
final class TelegramBot
{
    private const COMMAND = '/^\/(start|stop)(?:@(\w+))?(?:\s+(\S+))?\s*$/';
    private const PRIVATE_ONLY = 'Booking actions work only in a private chat with the bot.';

    public function __construct(
        private readonly BookingViewRepository $views,
        private readonly BookingService $bookings,
        private readonly TelegramServices $telegram,
        private readonly LinkCodes $linkCodes,
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly ?string $botUsername = null,
    ) {}

    /**
     * @param array<string, mixed> $update
     */
    public function handle(array $update): void
    {
        $callback = $update['callback_query'] ?? null;
        $message = $update['message'] ?? null;

        if (is_array($callback)) {
            $this->onButton($callback);
        } elseif (is_array($message) && is_string($message['text'] ?? null) && self::isPrivate($message)) {
            // Linking and unlinking only from private chats: in a group, anyone could use a code.
            $this->onCommand(self::chatId($message), trim($message['text']));
        }
    }

    /**
     * @param array<string, mixed> $callback
     */
    private function onButton(array $callback): void
    {
        $callbackId = is_string($callback['id'] ?? null) ? $callback['id'] : '';
        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $chat = self::chatId($message);
        $messageId = is_int($message['message_id'] ?? null) ? $message['message_id'] : 0;
        $data = CallbackData::parse(is_string($callback['data'] ?? null) ? $callback['data'] : '');

        $booking = $data === null ? null : $this->views->findById($data->bookingId);
        if ($data === null || $booking === null) {
            $this->answer($callbackId, 'This button is no longer valid.');

            return;
        }

        // Only a one-to-one chat proves who pressed: in a group, every member sees the buttons.
        $from = is_array($callback['from'] ?? null) ? ($callback['from']['id'] ?? null) : null;
        if (!self::isPrivate($message) || (string) $from !== $chat) {
            $this->answer($callbackId, self::PRIVATE_ONLY);

            return;
        }

        $actor = $this->telegram->directory->actorFor($chat, $booking->providerId);
        if ($actor === null) {
            $this->answer($callbackId, 'You are not allowed to act on this booking.');

            return;
        }

        try {
            $this->answer($callbackId, $this->act($data, $booking, $actor, $chat, $messageId));
        } catch (DomainError) {
            $this->settled($callbackId, $data->bookingId, $chat, $messageId);
        }
    }

    /**
     * @return string the notice shown to the person who pressed
     */
    private function act(CallbackData $data, BookingView $booking, Actor $actor, string $chat, int $messageId): string
    {
        switch ($data->action) {
            case CallbackAction::Confirm:
                $this->bookings->confirm($booking->id, $actor);
                $this->showOutcome($booking->id, $chat, $messageId);

                return 'Confirmed ✅';
            case CallbackAction::Reject:
                $this->bookings->reject($booking->id, $actor);
                $this->showOutcome($booking->id, $chat, $messageId);

                return 'Rejected. The customer will be emailed.';
            case CallbackAction::AskReject:
                $this->assertPending($booking);
                $this->edit($chat, $messageId, TelegramText::rejectPrompt($booking, $data->kind), Keyboards::rejectPrompt($booking->id, $data->kind));

                return 'Tap "Yes, reject" to reject this booking.';
            case CallbackAction::Back:
                $this->assertPending($booking);
                $this->edit($chat, $messageId, TelegramText::alert($booking, $data->kind), Keyboards::actions($booking->id, $data->kind));

                return 'OK';
        }
    }

    private function onCommand(string $chat, string $text): void
    {
        if ($chat === '' || preg_match(self::COMMAND, $text, $m) !== 1) {
            return;
        }
        $addressedTo = $m[2] ?? '';
        if ($addressedTo !== '' && $this->botUsername !== null && strcasecmp($addressedTo, $this->botUsername) !== 0) {
            return; // meant for another bot
        }

        if ($m[1] === 'stop') {
            $removed = $this->telegram->directory->unlink($chat);
            $this->reply($chat, $removed > 0 ? 'Unlinked. This chat will no longer get booking alerts.' : 'This chat was not linked.');

            return;
        }

        $code = $m[3] ?? '';
        if ($code === '') {
            $this->reply($chat, sprintf(
                "Hi! This is the booking assistant.\nThis chat's id is <code>%s</code>. Ask the site owner for a link to connect it.",
                htmlspecialchars($chat, ENT_QUOTES),
            ));

            return;
        }

        $label = $this->db->transaction(fn(): ?string => $this->linkCodes->redeem($code, $chat));
        $this->reply($chat, $label === null
            ? 'That link has expired or was already used. Ask for a new one.'
            : sprintf('Linked ✅ This chat will now get booking alerts for %s.', htmlspecialchars($label, ENT_QUOTES)));
    }

    /**
     * The booking was settled by someone else: say so and show the outcome instead of stale buttons.
     */
    private function settled(string $callbackId, int $bookingId, string $chat, int $messageId): void
    {
        $booking = $this->views->findById($bookingId);
        if ($booking === null) {
            $this->answer($callbackId, 'This booking no longer exists.');

            return;
        }
        $status = $booking->holdLapsed($this->clock->now()) ? 'expired' : str_replace('_', ' ', $booking->status->value);
        $this->showOutcome($bookingId, $chat, $messageId);
        $this->answer($callbackId, sprintf('This booking is already %s.', $status));
    }

    private function showOutcome(int $bookingId, string $chat, int $messageId): void
    {
        $booking = $this->views->findById($bookingId);
        if ($booking !== null) {
            $this->edit($chat, $messageId, TelegramText::resolution($booking));
            $this->telegram->log->forget($bookingId, $chat, $messageId);
        }
    }

    private function assertPending(BookingView $booking): void
    {
        $pending = in_array($booking->status, [BookingStatus::Held, BookingStatus::AwaitingVerification], true);
        if (!$pending || $booking->holdLapsed($this->clock->now())) {
            throw new BookingSettled();
        }
    }

    private function edit(string $chat, int $messageId, string $html, ?InlineKeyboard $keyboard = null): void
    {
        if ($messageId === 0) {
            return;
        }
        try {
            $this->telegram->api->editMessage($chat, $messageId, $html, $keyboard);
        } catch (TelegramApiError $e) {
            error_log('[consultdesk] telegram edit failed: ' . $e->getMessage());
        }
    }

    private function answer(string $callbackId, string $text): void
    {
        if ($callbackId === '') {
            return;
        }
        try {
            $this->telegram->api->answerCallback($callbackId, $text);
        } catch (TelegramApiError $e) {
            error_log('[consultdesk] telegram answer failed: ' . $e->getMessage());
        }
    }

    private function reply(string $chat, string $html): void
    {
        try {
            $this->telegram->api->sendMessage($chat, $html);
        } catch (TelegramApiError $e) {
            error_log('[consultdesk] telegram reply failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function isPrivate(array $message): bool
    {
        $chat = $message['chat'] ?? null;

        return is_array($chat) && ($chat['type'] ?? null) === 'private';
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function chatId(array $message): string
    {
        $chat = $message['chat'] ?? null;
        $id = is_array($chat) ? ($chat['id'] ?? null) : null;

        return is_int($id) || (is_string($id) && preg_match('/^-?\d+$/', $id) === 1) ? (string) $id : '';
    }
}
