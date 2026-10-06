<?php

declare(strict_types=1);

namespace ConsultDesk\Notify\Mail;

final class RenderedEmail
{
    public function __construct(
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
    ) {}
}
