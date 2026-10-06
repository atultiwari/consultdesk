<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Domain\Booking\BookingEvent;
use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\Outbox;
use ConsultDesk\Notify\PayloadReader;
use RuntimeException;

/**
 * Booking event → one "telegram.alert" job per staff chat (UTR to verify, request to approve),
 * or a "telegram.resolve" job that updates earlier alerts once the booking is settled.
 */
final class TelegramEventHandler implements JobHandler
{
    public const ALERT_JOB = 'telegram.alert';
    public const RESOLVE_JOB = 'telegram.resolve';

    public function __construct(
        private readonly BookingEvent $event,
        private readonly BookingViewRepository $views,
        private readonly Outbox $outbox,
        private readonly TelegramDirectory $directory,
    ) {}

    public function handle(array $payload): void
    {
        $bookingId = PayloadReader::int($payload, 'booking_id');
        $booking = $this->views->findById($bookingId) ?? throw new RuntimeException("Booking {$bookingId} not found.");

        $kind = $this->alertKind($booking);
        if ($kind !== null) {
            foreach ($this->directory->alertChats($booking->providerId) as $chat) {
                $this->outbox->enqueue(
                    self::ALERT_JOB,
                    ['booking_id' => $bookingId, 'kind' => $kind->value, 'chat_id' => $chat],
                    sprintf('%s:%s:%d:%s', self::ALERT_JOB, $kind->value, $bookingId, $chat),
                );
            }

            return;
        }

        if (in_array($this->event, [BookingEvent::Confirmed, BookingEvent::Rejected, BookingEvent::Cancelled, BookingEvent::Expired], true)) {
            $this->outbox->enqueue(
                self::RESOLVE_JOB,
                ['booking_id' => $bookingId],
                sprintf('%s:%s:%d', self::RESOLVE_JOB, $this->event->value, $bookingId),
            );
        }
    }

    private function alertKind(BookingView $booking): ?AlertKind
    {
        return match (true) {
            $this->event === BookingEvent::UtrSubmitted && $booking->status === BookingStatus::AwaitingVerification => AlertKind::VerifyPayment,
            $this->event === BookingEvent::Held && $booking->status === BookingStatus::Held && $booking->paymentMethod === PaymentMethod::Free => AlertKind::ApprovalNeeded,
            // Confirmed without anyone pressing a button: paid online, or free with no approval.
            $this->event === BookingEvent::Confirmed && $booking->status === BookingStatus::Confirmed
                && ($booking->paymentMethod === PaymentMethod::RazorpayLink || ($booking->paymentMethod === PaymentMethod::Free && !$booking->requiresApproval)) => AlertKind::NewBooking,
            $this->event === BookingEvent::PaidLate => AlertKind::RefundNeeded,
            default => null,
        };
    }
}
