<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use DateTimeImmutable;

final class GoogleConnection
{
    /**
     * @param list<string> $busyCalendarIds calendars whose events block availability
     */
    public function __construct(
        public readonly int $providerId,
        public readonly ?string $accountEmail,
        #[\SensitiveParameter]
        public readonly string $refreshToken,
        #[\SensitiveParameter]
        public readonly ?string $accessToken,
        public readonly ?DateTimeImmutable $accessExpiresAt,
        public readonly array $busyCalendarIds,
        public readonly ?string $targetCalendarId,
        public readonly bool $active,
    ) {}
}
