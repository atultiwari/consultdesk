<?php

declare(strict_types=1);

namespace ConsultDesk\Payments;

use ConsultDesk\Infra\Settings;

/**
 * The owner's on/off switches for each way to pay (settings key "payments"). Both default to on;
 * each still needs its details (a provider's UPI ID, Razorpay keys) before customers see it.
 */
final class PaymentSwitches
{
    public const KEY = 'payments';

    public function __construct(
        public readonly bool $upiEnabled = true,
        public readonly bool $razorpayEnabled = true,
    ) {}

    public static function load(Settings $settings): self
    {
        $stored = $settings->get(self::KEY);

        return new self(($stored['upi_enabled'] ?? true) !== false, ($stored['razorpay_enabled'] ?? true) !== false);
    }

    /**
     * @return array{upi_enabled: bool, razorpay_enabled: bool}
     */
    public function toArray(): array
    {
        return ['upi_enabled' => $this->upiEnabled, 'razorpay_enabled' => $this->razorpayEnabled];
    }
}
