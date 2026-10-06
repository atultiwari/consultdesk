<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

enum AlertKind: string
{
    case VerifyPayment = 'v';
    case ApprovalNeeded = 'a';
}
