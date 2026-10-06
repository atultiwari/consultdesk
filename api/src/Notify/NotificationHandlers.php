<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use ConsultDesk\Domain\Booking\BookingEvent;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Infra\Crypto;
use ConsultDesk\Notify\Mail\BookingEmails;
use ConsultDesk\Notify\Mail\Mailer;

/**
 * The job-type → handler map the outbox worker runs with.
 */
final class NotificationHandlers
{
    /**
     * @return array<string, JobHandler>
     */
    public static function build(
        BookingViewRepository $views,
        Outbox $outbox,
        Mailer $mailer,
        BookingEmails $emails,
        Crypto $crypto,
        string $appUrl,
    ): array {
        $handlers = [BookingEventHandler::EMAIL_JOB => new BookingEmailHandler($views, $emails, $mailer, $crypto, $appUrl)];
        foreach (BookingEvent::cases() as $event) {
            $handlers[$event->value] = new BookingEventHandler($event, $views, $outbox);
        }

        return $handlers;
    }
}
