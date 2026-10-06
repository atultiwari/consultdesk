<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Support;

use ConsultDesk\Notify\Mail\EmailMessage;
use ConsultDesk\Notify\Mail\Mailer;
use RuntimeException;

/**
 * Collects messages instead of sending them. Can be told to fail the next N sends.
 */
final class ArrayMailer implements Mailer
{
    /** @var list<EmailMessage> */
    public array $sent = [];

    public function __construct(private int $failNext = 0)
    {
    }

    public function send(EmailMessage $message): void
    {
        if ($this->failNext > 0) {
            $this->failNext--;
            throw new RuntimeException('SMTP connect() failed.');
        }
        $this->sent[] = $message;
    }

    /**
     * @return list<string> subjects in send order
     */
    public function subjects(): array
    {
        return array_map(static fn (EmailMessage $m): string => $m->subject, $this->sent);
    }
}
