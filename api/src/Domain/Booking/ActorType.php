<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

enum ActorType: string
{
    case User = 'user';
    case System = 'system';
    case Telegram = 'telegram';
    case Webhook = 'webhook';
    case Customer = 'customer';
}
