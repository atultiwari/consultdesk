<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\Mail\EmailBody;
use ConsultDesk\Notify\Mail\EmailMessage;
use ConsultDesk\Notify\Mail\Mailer;
use ConsultDesk\Notify\PayloadReader;

/**
 * Emails a new user a link to choose their password. Nothing happens if they already have one.
 */
final class InviteEmailHandler implements JobHandler
{
    public function __construct(
        private readonly PasswordResets $resets,
        private readonly Mailer $mailer,
        private readonly string $orgName,
        private readonly string $appUrl,
        private readonly string $adminPath,
    ) {}

    public function handle(array $payload): void
    {
        $invite = $this->resets->issueInvite(PayloadReader::int($payload, 'user_id'));
        if ($invite === null) {
            return;
        }

        $link = sprintf('%s/%s/welcome?token=%s', $this->appUrl, $this->adminPath, $invite->token);
        $body = (new EmailBody())
            ->heading($invite->name === null ? 'Welcome' : sprintf('Welcome, %s', $invite->name))
            ->paragraph(sprintf('You have been invited to manage bookings for %s. Choose a password to sign in.', $this->orgName))
            ->button('Choose your password', $link)
            ->note(sprintf('The link works once, for %d hours. If you weren’t expecting this, you can ignore it.', PasswordResets::INVITE_TTL_HOURS));

        $this->mailer->send(new EmailMessage([$invite->email], sprintf('You’re invited to %s', $this->orgName), $body->toText(), $body->toHtml()));
    }
}
