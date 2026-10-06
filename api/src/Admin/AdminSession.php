<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

final class AdminSession
{
    public function __construct(
        public readonly AdminUser $user,
        #[\SensitiveParameter]
        public readonly string $token,
        public readonly string $csrfToken,
    ) {}
}
