<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Infra\Crypto;
use ConsultDesk\Notify\Mail\BookingEmails;
use ConsultDesk\Notify\Mail\EmailMessage;
use ConsultDesk\Notify\Mail\EmailTemplate;
use ConsultDesk\Notify\Mail\Mailer;
use RuntimeException;

/**
 * Renders and sends one booking email. Rendering happens here, at send time, so the status-page
 * token is decrypted only in memory and never stored in the queue.
 */
final class BookingEmailHandler implements JobHandler
{
    public function __construct(
        private readonly BookingViewRepository $views,
        private readonly BookingEmails $emails,
        private readonly Mailer $mailer,
        private readonly Crypto $crypto,
        private readonly string $appUrl,
    ) {
    }

    public function handle(array $payload): void
    {
        $bookingId = PayloadReader::int($payload, 'booking_id');
        $template = EmailTemplate::from(PayloadReader::string($payload, 'template'));
        $booking = $this->views->findById($bookingId) ?? throw new RuntimeException("Booking {$bookingId} not found.");

        $rendered = $this->emails->render($template, $booking, $this->statusUrl($booking));

        if ($template->isForCustomer()) {
            $this->mailer->send(EmailMessage::fromRendered([$booking->customerEmail], $rendered, $booking->providerNotifyEmail));

            return;
        }

        $staff = $this->views->staffEmails($booking);
        if ($staff !== []) {
            $this->mailer->send(EmailMessage::fromRendered($staff, $rendered, $booking->customerEmail));
        }
    }

    private function statusUrl(BookingView $booking): string
    {
        $base = sprintf('%s/b/%s', $this->appUrl, rawurlencode($booking->ref));
        if ($booking->publicTokenEnc === null) {
            return $base;
        }

        return $base . '?t=' . rawurlencode($this->crypto->decrypt($booking->publicTokenEnc));
    }
}
