<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use DateTimeImmutable;
use DateTimeZone;

final class HoldRequest
{
    public readonly DateTimeImmutable $start;

    /**
     * @param array<string, mixed> $answers intake answers, already validated against the service's questions
     */
    public function __construct(
        public readonly int $providerId,
        public readonly int $serviceId,
        DateTimeImmutable $start,
        public readonly Customer $customer,
        public readonly PaymentMethod $paymentMethod,
        public readonly array $answers = [],
        /** A discount code the customer entered, checked again inside the booking transaction. */
        public readonly ?string $couponCode = null,
    ) {
        $this->start = $start->setTimezone(new DateTimeZone('UTC'));
    }
}
