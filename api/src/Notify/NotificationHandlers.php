<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use ConsultDesk\Domain\Booking\BookingEvent;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Crypto;
use ConsultDesk\Infra\SystemClock;
use ConsultDesk\Notify\Mail\BookingEmails;
use ConsultDesk\Notify\Mail\Mailer;
use ConsultDesk\Telegram\TelegramAlertHandler;
use ConsultDesk\Telegram\TelegramEventHandler;
use ConsultDesk\Telegram\TelegramResolveHandler;
use ConsultDesk\Telegram\TelegramServices;

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
        ?TelegramServices $telegram = null,
        ?Clock $clock = null,
    ): array {
        $handlers = [BookingEventHandler::EMAIL_JOB => new BookingEmailHandler($views, $emails, $mailer, $crypto, $appUrl)];
        if ($telegram !== null) {
            $handlers[TelegramEventHandler::ALERT_JOB] = new TelegramAlertHandler($views, $telegram, $clock ?? new SystemClock());
            $handlers[TelegramEventHandler::RESOLVE_JOB] = new TelegramResolveHandler($views, $telegram);
        }

        foreach (BookingEvent::cases() as $event) {
            $email = new BookingEventHandler($event, $views, $outbox);
            $handlers[$event->value] = $telegram === null
                ? $email
                : new CompositeJobHandler([$email, new TelegramEventHandler($event, $views, $outbox, $telegram->directory)]);
        }

        return $handlers;
    }
}
