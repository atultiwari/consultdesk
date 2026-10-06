<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

enum CallbackAction: string
{
    case Confirm = 'c';
    /** First tap on Reject: ask before doing it. */
    case AskReject = 'r';
    case Reject = 'x';
    /** "Back" from the reject prompt. */
    case Back = 'b';
}
