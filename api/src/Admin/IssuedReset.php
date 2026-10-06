<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

/**
 * A freshly made reset link, held only in memory until it is emailed.
 */
final class IssuedReset
{
    public function __construct(
        public readonly string $email,
        #[\SensitiveParameter]
        public readonly string $token,
    ) {}
}
