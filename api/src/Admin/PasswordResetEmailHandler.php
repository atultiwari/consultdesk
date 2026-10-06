<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Infra\Crypto;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\Mail\EmailBody;
use ConsultDesk\Notify\Mail\EmailMessage;
use ConsultDesk\Notify\Mail\Mailer;
use ConsultDesk\Notify\PayloadReader;
use PDO;

/**
 * Sends the reset link, then forgets the encrypted copy of the token.
 */
final class PasswordResetEmailHandler implements JobHandler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Mailer $mailer,
        private readonly Crypto $crypto,
        private readonly string $appUrl,
        private readonly string $adminPath,
    ) {}

    public function handle(array $payload): void
    {
        $resetId = PayloadReader::int($payload, 'reset_id');
        $statement = $this->pdo->prepare(
            'SELECT r.token_enc, u.email FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.id = :id AND r.used_at IS NULL',
        );
        $statement->execute(['id' => $resetId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['token_enc'] === null) {
            return; // already used, or already sent
        }

        $link = sprintf('%s/%s/reset?token=%s', $this->appUrl, $this->adminPath, $this->crypto->decrypt((string) $row['token_enc']));
        $body = (new EmailBody())
            ->heading('Reset your password')
            ->paragraph('Someone (hopefully you) asked to reset the password for this admin account. The link works once, for 30 minutes.')
            ->button('Choose a new password', $link)
            ->note('If you didn’t ask for this, ignore this email: your password stays the same.');

        $this->mailer->send(new EmailMessage([(string) $row['email']], 'Reset your admin password', $body->toText(), $body->toHtml()));
        $this->pdo->prepare('UPDATE password_resets SET token_enc = NULL WHERE id = :id')->execute(['id' => $resetId]);
    }
}
