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
 * Sends one alert with action buttons to one chat, unless the booking was settled in the meantime.
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

        $expected = $kind === AlertKind::VerifyPayment ? BookingStatus::AwaitingVerification : BookingStatus::Held;
        if ($booking->status !== $expected || $booking->holdLapsed($this->clock->now())) {
            return;
        }

        $messageId = $this->telegram->api->sendMessage($chat, TelegramText::alert($booking, $kind), Keyboards::actions($bookingId, $kind));
        $this->telegram->log->record($bookingId, $chat, $messageId);
    }
}
