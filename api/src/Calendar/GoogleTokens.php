<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

final class GoogleTokens
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $accessToken,
        #[\SensitiveParameter]
        public readonly ?string $refreshToken,
        public readonly int $expiresIn,
        public readonly string $scope,
    ) {}
}
