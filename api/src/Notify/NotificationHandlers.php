<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use ConsultDesk\Calendar\CalendarServices;
use ConsultDesk\Calendar\GoogleCalendar;
use ConsultDesk\Calendar\GoogleCreateEventHandler;
use ConsultDesk\Calendar\GoogleDeleteEventHandler;
use ConsultDesk\Calendar\GoogleEventHandler;
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
        ?CalendarServices $calendar = null,
    ): array {
        $handlers = [BookingEventHandler::EMAIL_JOB => new BookingEmailHandler($views, $emails, $mailer, $crypto, $appUrl)];
        if ($telegram !== null) {
            $handlers[TelegramEventHandler::ALERT_JOB] = new TelegramAlertHandler($views, $telegram, $clock ?? new SystemClock());
            $handlers[TelegramEventHandler::RESOLVE_JOB] = new TelegramResolveHandler($views, $telegram);
        }

        if ($calendar !== null) {
            $handlers[GoogleEventHandler::CREATE_JOB] = new GoogleCreateEventHandler($views, $calendar->calendar, $calendar->links);
            $handlers[GoogleEventHandler::DELETE_JOB] = new GoogleDeleteEventHandler($views, $calendar->calendar, $calendar->links);
            $handlers[GoogleCalendar::DISCONNECTED_JOB] = $calendar->disconnected;
        }

        foreach (BookingEvent::cases() as $event) {
            $fanOut = [new BookingEventHandler($event, $views, $outbox, $clock)];
            if ($telegram !== null) {
                $fanOut[] = new TelegramEventHandler($event, $views, $outbox, $telegram->directory);
            }
            if ($calendar !== null) {
                $fanOut[] = new GoogleEventHandler($event, $views, $outbox);
            }
            $handlers[$event->value] = count($fanOut) === 1 ? $fanOut[0] : new CompositeJobHandler($fanOut);
        }

        return $handlers;
    }
}
