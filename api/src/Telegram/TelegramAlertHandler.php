<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\PayloadReader;
use RuntimeException;

/**
 * Sends one alert to one chat: with action buttons (unless the booking was settled in the meantime),
 * or, for a notice, as plain information.
 */
final class TelegramAlertHandler implements JobHandler
{
    public function __construct(
        private readonly BookingViewRepository $views,
        private readonly TelegramServices $telegram,
        private readonly Clock $clock,
    ) {}

    public function handle(array $payload): void
    {
        $bookingId = PayloadReader::int($payload, 'booking_id');
        $kind = AlertKind::from(PayloadReader::string($payload, 'kind'));
        $chat = PayloadReader::string($payload, 'chat_id');
        $booking = $this->views->findById($bookingId) ?? throw new RuntimeException("Booking {$bookingId} not found.");

        if (!$kind->needsAction()) {
            // Information only: no buttons, and nothing to update later.
            $this->telegram->api->sendMessage($chat, TelegramText::alert($booking, $kind));

            return;
        }

        $expected = $kind === AlertKind::VerifyPayment ? BookingStatus::AwaitingVerification : BookingStatus::Held;
        if ($booking->status !== $expected || $booking->holdLapsed($this->clock->now())) {
            return;
        }

        $messageId = $this->telegram->api->sendMessage($chat, TelegramText::alert($booking, $kind), Keyboards::actions($bookingId, $kind));
        $this->telegram->log->record($bookingId, $chat, $messageId);
    }
}
