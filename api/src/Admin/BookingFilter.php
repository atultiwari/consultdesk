<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Domain\Booking\BookingStatus;
use DateTimeImmutable;

/**
 * What the admin booking list is narrowed to. A null field means "any".
 */
final class BookingFilter
{
    public function __construct(
        public readonly ?int $providerScope = null,
        public readonly ?int $providerId = null,
        public readonly ?BookingStatus $status = null,
        public readonly ?string $search = null,
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
        public readonly int $page = 1,
        public readonly int $perPage = 25,
    ) {}
}
