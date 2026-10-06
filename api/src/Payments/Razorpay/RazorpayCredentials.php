<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

/**
 * One Razorpay account's API keys, decrypted only for the request that needs them.
 */
final class RazorpayCredentials
{
    public function __construct(
        public readonly string $keyId,
        #[\SensitiveParameter]
        public readonly string $keySecret,
        #[\SensitiveParameter]
        public readonly ?string $webhookSecret = null,
        /** null for the organisation's account, otherwise the provider whose own account this is */
        public readonly ?int $providerId = null,
    ) {}

    public function isTestMode(): bool
    {
        return str_starts_with($this->keyId, 'rzp_test_');
    }
}
