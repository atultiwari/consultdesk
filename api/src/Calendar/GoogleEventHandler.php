<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Booking\BookingEvent;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\Outbox;
use ConsultDesk\Notify\PayloadReader;
use RuntimeException;

/**
 * Booking event → calendar job: create the event on confirmation, delete it on cancellation.
 */
final class GoogleEventHandler implements JobHandler
{
    public const CREATE_JOB = 'google.create_event';
    public const DELETE_JOB = 'google.delete_event';

    public function __construct(
        private readonly BookingEvent $event,
        private readonly BookingViewRepository $views,
        private readonly Outbox $outbox,
    ) {}

    public function handle(array $payload): void
    {
        $bookingId = PayloadReader::int($payload, 'booking_id');
        $booking = $this->views->findById($bookingId) ?? throw new RuntimeException("Booking {$bookingId} not found.");

        if ($this->event === BookingEvent::Confirmed && $booking->calendarConnected) {
            $this->outbox->enqueue(self::CREATE_JOB, ['booking_id' => $bookingId], self::CREATE_JOB . ':' . $bookingId);
        } elseif ($this->event === BookingEvent::Cancelled && $booking->gcalEventId !== null) {
            $this->outbox->enqueue(self::DELETE_JOB, ['booking_id' => $bookingId], self::DELETE_JOB . ':' . $bookingId);
        }
    }
}
