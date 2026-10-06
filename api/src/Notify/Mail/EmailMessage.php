<?php

declare(strict_types=1);

namespace ConsultDesk\Notify\Mail;

use InvalidArgumentException;

final class EmailMessage
{
    /**
     * @param non-empty-list<string> $to
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly ?string $replyTo = null,
    ) {
        foreach ($to as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Invalid recipient address.');
            }
        }
    }

    /**
     * @param non-empty-list<string> $to
     */
    public static function fromRendered(array $to, RenderedEmail $email, ?string $replyTo = null): self
    {
        return new self($to, $email->subject, $email->text, $email->html, $replyTo);
    }
}
