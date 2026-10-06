<?php

declare(strict_types=1);

namespace ConsultDesk\Payments;

final class UpiInstructions
{
    public function __construct(
        public readonly string $vpa,
        public readonly string $payeeName,
        public readonly string $amount,
        public readonly string $amountDisplay,
        public readonly string $uri,
        public readonly ?string $whatsappUrl,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'vpa' => $this->vpa,
            'payee_name' => $this->payeeName,
            'amount' => $this->amount,
            'amount_display' => $this->amountDisplay,
            'upi_uri' => $this->uri,
            'whatsapp_url' => $this->whatsappUrl,
        ];
    }
}
