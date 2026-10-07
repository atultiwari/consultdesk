<?php

declare(strict_types=1);

namespace ConsultDesk\Customer;

use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\Mail\EmailBody;
use ConsultDesk\Notify\Mail\EmailMessage;
use ConsultDesk\Notify\Mail\Mailer;
use ConsultDesk\Notify\PayloadReader;

/**
 * Makes the "My bookings" sign-in link and emails it; nothing for an address without bookings.
 */
final class CustomerLinkEmailHandler implements JobHandler
{
    public function __construct(
        private readonly CustomerAccess $access,
        private readonly Mailer $mailer,
        private readonly string $appUrl,
        private readonly string $orgName,
    ) {}

    public function handle(array $payload): void
    {
        $email = PayloadReader::string($payload, 'email');
        $token = $this->access->issue($email);
        if ($token === null) {
            return;
        }

        $link = sprintf('%s/my-bookings?token=%s', $this->appUrl, $token);
        $body = (new EmailBody())
            ->heading('Your bookings')
            ->paragraph(sprintf('Here is your link to see your bookings with %s. It works once, for %d minutes.', $this->orgName, CustomerAccess::LINK_MINUTES))
            ->button('See my bookings', $link)
            ->note('If you didn’t ask for this, you can ignore this email.');

        $this->mailer->send(new EmailMessage([$email], 'Your bookings', $body->toText(), $body->toHtml()));
    }
}
