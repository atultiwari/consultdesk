<?php

declare(strict_types=1);

namespace ConsultDesk\Notify\Mail;

interface Mailer
{
    /**
     * @throws \RuntimeException when the message could not be handed to the mail server
     */
    public function send(EmailMessage $message): void;
}
