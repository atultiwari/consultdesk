<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use DateTimeImmutable;

/**
 * Result of a successful hold. publicToken is shown once (status-page link) and only its hash is stored.
 */
final class HeldBooking
{
    public function __construct(
        public readonly int $id,
        public readonly string $ref,
        #[\SensitiveParameter]
        public readonly string $publicToken,
        public readonly DateTimeImmutable $holdExpiresAt,
    ) {}
}
