<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

final class ServiceRecord
{
    /**
     * @param list<PaymentMethod> $paymentMethods
     */
    public function __construct(
        public readonly int $id,
        public readonly int $providerId,
        public readonly int $durationMinutes,
        public readonly int $priceMinor,
        public readonly string $currency,
        public readonly bool $requiresApproval,
        public readonly array $paymentMethods,
    ) {}

    public function isFree(): bool
    {
        return $this->priceMinor === 0;
    }
}
