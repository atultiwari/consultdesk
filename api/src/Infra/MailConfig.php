<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use InvalidArgumentException;

final class MailConfig
{
    /**
     * @param 'tls'|'ssl'|null $encryption
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $username,
        #[\SensitiveParameter]
        public readonly ?string $password,
        public readonly ?string $encryption,
        public readonly string $fromEmail,
        public readonly string $fromName,
    ) {
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('SMTP_HOST and SMTP_PORT are required.');
        }
        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('MAIL_FROM must be a valid email address.');
        }
    }
}
