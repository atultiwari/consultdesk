<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\Mail\EmailBody;
use ConsultDesk\Notify\Mail\EmailMessage;
use ConsultDesk\Notify\Mail\Mailer;
use ConsultDesk\Notify\PayloadReader;

/**
 * Makes the reset link and emails it. Nothing happens for an unknown address. If sending fails the
 * job is retried with a new link, which replaces the unsent one.
 */
final class PasswordResetEmailHandler implements JobHandler
{
    public function __construct(
        private readonly PasswordResets $resets,
        private readonly Mailer $mailer,
        private readonly string $appUrl,
        private readonly string $adminPath,
    ) {}

    public function handle(array $payload): void
    {
        $reset = $this->resets->issue(PayloadReader::string($payload, 'email'));
        if ($reset === null) {
            return;
        }

        $link = sprintf('%s/%s/reset?token=%s', $this->appUrl, $this->adminPath, $reset->token);
        $body = (new EmailBody())
            ->heading('Reset your password')
            ->paragraph(sprintf(
                'Someone (hopefully you) asked to reset the password for this admin account. The link works once, for %d minutes.',
                PasswordResets::TTL_MINUTES,
            ))
            ->button('Choose a new password', $link)
            ->note('If you didn’t ask for this, ignore this email: your password stays the same.');

        $this->mailer->send(new EmailMessage([$reset->email], 'Reset your admin password', $body->toText(), $body->toHtml()));
    }
}
