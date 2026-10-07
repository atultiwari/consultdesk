<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Availability\Interval;
use DateTimeImmutable;

final class NewBooking
{
    /**
     * @param array<string, mixed> $answers
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $publicTokenHash,
        public readonly string $publicTokenEnc,
        public readonly int $providerId,
        public readonly int $serviceId,
        public readonly Interval $slot,
        public readonly Customer $customer,
        public readonly array $answers,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly PaymentMethod $paymentMethod,
        public readonly DateTimeImmutable $holdExpiresAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?int $couponId = null,
        public readonly ?string $couponCode = null,
        public readonly int $discountMinor = 0,
        public readonly ?string $couponEmail = null,
    ) {}

    public function withRef(string $ref): self
    {
        return new self(
            $ref,
            $this->publicTokenHash,
            $this->publicTokenEnc,
            $this->providerId,
            $this->serviceId,
            $this->slot,
            $this->customer,
            $this->answers,
            $this->amountMinor,
            $this->currency,
            $this->paymentMethod,
            $this->holdExpiresAt,
            $this->createdAt,
            $this->couponId,
            $this->couponCode,
            $this->discountMinor,
            $this->couponEmail,
        );
    }
}
