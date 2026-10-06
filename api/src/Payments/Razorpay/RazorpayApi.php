<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

/**
 * The few Razorpay calls ConsultDesk makes (Payment Links API).
 */
interface RazorpayApi
{
    /**
     * @throws RazorpayError
     */
    public function createPaymentLink(RazorpayCredentials $credentials, PaymentLinkRequest $request): PaymentLink;

    /**
     * @throws RazorpayError
     */
    public function cancelPaymentLink(RazorpayCredentials $credentials, string $linkId): void;

    /**
     * Confirms the keys work.
     *
     * @throws RazorpayError
     */
    public function check(RazorpayCredentials $credentials): void;
}
