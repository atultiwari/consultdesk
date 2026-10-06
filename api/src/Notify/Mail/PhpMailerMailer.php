<?php

declare(strict_types=1);

namespace ConsultDesk\Notify\Mail;

use ConsultDesk\Infra\MailConfig;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Sends over SMTP with PHPMailer, e.g. through the mailbox that comes with the hosting plan.
 */
final class PhpMailerMailer implements Mailer
{
    public function __construct(
        private readonly MailConfig $config,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function send(EmailMessage $message): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->config->host;
            $mail->Port = $this->config->port;
            $mail->Timeout = $this->timeoutSeconds;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->SMTPAutoTLS = $this->config->encryption !== null;
            if ($this->config->encryption !== null) {
                $mail->SMTPSecure = $this->config->encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            }
            if ($this->config->username !== null) {
                $mail->SMTPAuth = true;
                $mail->Username = $this->config->username;
                $mail->Password = (string) $this->config->password;
            }

            $mail->setFrom($this->config->fromEmail, $this->config->fromName);
            foreach ($message->to as $address) {
                $mail->addAddress($address);
            }
            if ($message->replyTo !== null) {
                $mail->addReplyTo($message->replyTo);
            }

            $mail->isHTML(true);
            $mail->Subject = $message->subject;
            $mail->Body = $message->html;
            $mail->AltBody = $message->text;
            $mail->send();
        } catch (PhpMailerException $e) {
            // ErrorInfo describes the SMTP failure without including credentials.
            throw new RuntimeException('Email could not be sent: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
