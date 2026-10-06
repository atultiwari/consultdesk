<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use ConsultDesk\Domain\Booking\BookingEvent;
use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Notify\Mail\EmailTemplate;
use RuntimeException;

/**
 * Turns one booking event into one outbox job per email, so a failed send is retried on its own
 * and never re-sends the others. Events that are stale by the time they run (e.g. a "held" event
 * for a booking the customer has already paid) send nothing.
 */
final class BookingEventHandler implements JobHandler
{
    public const EMAIL_JOB = 'email.booking';

    public function __construct(
        private readonly BookingEvent $event,
        private readonly BookingViewRepository $views,
        private readonly Outbox $outbox,
    ) {
    }

    public function handle(array $payload): void
    {
        $bookingId = PayloadReader::int($payload, 'booking_id');
        $booking = $this->views->findById($bookingId) ?? throw new RuntimeException("Booking {$bookingId} not found.");

        foreach ($this->templatesFor($booking) as $template) {
            $this->outbox->enqueue(
                self::EMAIL_JOB,
                ['booking_id' => $bookingId, 'template' => $template->value],
                sprintf('%s:%d', $template->value, $bookingId),
            );
        }
    }

    /**
     * @return list<EmailTemplate>
     */
    private function templatesFor(BookingView $booking): array
    {
        return match ($this->event) {
            BookingEvent::Held => match (true) {
                $booking->status !== BookingStatus::Held => [],
                $booking->paymentMethod === PaymentMethod::Upi => [EmailTemplate::CustomerPaymentDue],
                $booking->paymentMethod === PaymentMethod::Free => [EmailTemplate::CustomerRequestReceived, EmailTemplate::StaffApprovalNeeded],
                default => [],
            },
            BookingEvent::UtrSubmitted => $booking->status === BookingStatus::AwaitingVerification
                ? [EmailTemplate::CustomerPaymentReceived, EmailTemplate::StaffVerifyPayment]
                : [],
            BookingEvent::Confirmed => $booking->status === BookingStatus::Confirmed
                ? [EmailTemplate::CustomerConfirmed, EmailTemplate::StaffConfirmed]
                : [],
            BookingEvent::Rejected => [EmailTemplate::CustomerRejected],
            BookingEvent::Cancelled => [EmailTemplate::CustomerCancelled],
            BookingEvent::Expired => [EmailTemplate::CustomerExpired],
        };
    }
}
