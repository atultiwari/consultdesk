<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

use ConsultDesk\Domain\Booking\BookingEvent;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\Outbox;
use ConsultDesk\Notify\PayloadReader;

/**
 * Booking event → Razorpay job: once an unpaid online booking expires, is cancelled or rejected,
 * its payment link is cancelled so nobody pays for a slot they no longer hold.
 */
final class RazorpayEventHandler implements JobHandler
{
    public const CANCEL_JOB = 'razorpay.cancel_link';

    public function __construct(
        private readonly BookingEvent $event,
        private readonly BookingViewRepository $views,
        private readonly Outbox $outbox,
    ) {}

    public function handle(array $payload): void
    {
        if (!in_array($this->event, [BookingEvent::Expired, BookingEvent::Cancelled, BookingEvent::Rejected], true)) {
            return;
        }
        $bookingId = PayloadReader::int($payload, 'booking_id');
        if ($this->views->findById($bookingId)?->gatewayRef !== null) {
            $this->outbox->enqueue(self::CANCEL_JOB, ['booking_id' => $bookingId], self::CANCEL_JOB . ':' . $bookingId);
        }
    }
}
