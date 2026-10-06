<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\Mail\EmailBody;
use ConsultDesk\Notify\Mail\EmailMessage;
use ConsultDesk\Notify\Mail\Mailer;
use ConsultDesk\Notify\PayloadReader;
use PDO;

/**
 * Tells staff once when a provider's Google access stops working.
 */
final class GoogleDisconnectedHandler implements JobHandler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BookingViewRepository $views,
        private readonly Mailer $mailer,
    ) {}

    public function handle(array $payload): void
    {
        $providerId = PayloadReader::int($payload, 'provider_id');
        $statement = $this->pdo->prepare('SELECT name, slug FROM providers WHERE id = :id');
        $statement->execute(['id' => $providerId]);
        $provider = $statement->fetch(PDO::FETCH_ASSOC);
        $to = $this->views->staffEmailsForProvider($providerId);
        if (!is_array($provider) || $to === []) {
            return;
        }

        $name = (string) $provider['name'];
        $body = (new EmailBody())
            ->heading('Google Calendar disconnected')
            ->paragraph("Google stopped accepting ConsultDesk's access to {$name}'s calendar (the permission was removed or expired).")
            ->paragraph('Bookings still work, but until it is reconnected their Google calendar is not checked for clashes and new bookings are not added to it.')
            ->note(sprintf('To reconnect, run: php api/bin/google.php connect %s', (string) $provider['slug']));

        $this->mailer->send(new EmailMessage($to, "Google Calendar disconnected for {$name}", $body->toText(), $body->toHtml()));
    }
}
