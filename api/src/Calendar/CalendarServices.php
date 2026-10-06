<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

final class CalendarServices
{
    public function __construct(
        public readonly GoogleCalendar $calendar,
        public readonly CalendarLinks $links,
        public readonly GoogleDisconnectedHandler $disconnected,
    ) {}
}
