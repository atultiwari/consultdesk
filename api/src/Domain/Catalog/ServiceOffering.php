<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Catalog;

use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Notify\Money;

final class ServiceOffering
{
    /**
     * @param list<PaymentMethod> $paymentMethods as configured on the service
     */
    public function __construct(
        public readonly int $id,
        public readonly int $providerId,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $tagline,
        public readonly ?string $description,
        public readonly ?string $audience,
        public readonly int $durationMinutes,
        public readonly int $priceMinor,
        public readonly string $currency,
        public readonly bool $requiresApproval,
        public readonly array $paymentMethods,
        public readonly QuestionSet $questions,
    ) {}

    /**
     * Methods a customer can actually use right now. Free services are always "free"; paid ones offer
     * each of the service's methods the provider can take (see ProviderProfile).
     *
     * @return list<PaymentMethod>
     */
    public function availablePaymentMethods(ProviderProfile $provider): array
    {
        if ($this->priceMinor === 0) {
            return [PaymentMethod::Free];
        }

        return array_values(array_filter(
            $this->paymentMethods,
            static fn(PaymentMethod $m): bool => match ($m) {
                PaymentMethod::Upi => $provider->acceptsUpi,
                PaymentMethod::RazorpayLink => $provider->acceptsRazorpay,
                PaymentMethod::Free => false,
            },
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(ProviderProfile $provider): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'audience' => $this->audience,
            'duration_minutes' => $this->durationMinutes,
            'price_minor' => $this->priceMinor,
            'currency' => $this->currency,
            'price_display' => Money::format($this->priceMinor, $this->currency),
            'requires_approval' => $this->requiresApproval,
            'payment_methods' => array_map(static fn(PaymentMethod $m): string => $m->value, $this->availablePaymentMethods($provider)),
            'questions' => $this->questions->toArray(),
        ];
    }
}
