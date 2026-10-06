<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\PayloadReader;
use RuntimeException;

/**
 * Replaces the buttons on earlier alerts with the booking's outcome.
 */
final class TelegramResolveHandler implements JobHandler
{
    public function __construct(
        private readonly BookingViewRepository $views,
        private readonly TelegramServices $telegram,
    ) {}

    public function handle(array $payload): void
    {
        $bookingId = PayloadReader::int($payload, 'booking_id');
        $booking = $this->views->findById($bookingId) ?? throw new RuntimeException("Booking {$bookingId} not found.");

        foreach ($this->telegram->log->forBooking($bookingId) as $message) {
            $this->telegram->api->editMessage($message['chat'], $message['message'], TelegramText::resolution($booking));
            $this->telegram->log->forget($bookingId, $message['chat'], $message['message']);
        }
    }
}
