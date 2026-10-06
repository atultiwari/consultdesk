<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Support;

use ConsultDesk\Payments\Razorpay\PaymentLink;
use ConsultDesk\Payments\Razorpay\PaymentLinkRequest;
use ConsultDesk\Payments\Razorpay\RazorpayApi;
use ConsultDesk\Payments\Razorpay\RazorpayCredentials;
use ConsultDesk\Payments\Razorpay\RazorpayError;

/**
 * In-memory Razorpay. Records which account each call used. Placeholder ids only.
 */
final class FakeRazorpayApi implements RazorpayApi
{
    /** @var list<array{key: string, request: PaymentLinkRequest, id: string}> */
    public array $created = [];
    /** @var list<array{key: string, id: string}> */
    public array $cancelled = [];
    public bool $down = false;
    public bool $rejectKeys = false;
    private int $next = 1;

    public function createPaymentLink(RazorpayCredentials $credentials, PaymentLinkRequest $request): PaymentLink
    {
        if ($this->down) {
            throw new RazorpayError('Razorpay is unreachable.');
        }
        $id = sprintf('plink_test%06d', $this->next++);
        $this->created[] = ['key' => $credentials->keyId, 'request' => $request, 'id' => $id];

        return new PaymentLink($id, 'https://rzp.example.test/' . $id);
    }

    public function cancelPaymentLink(RazorpayCredentials $credentials, string $linkId): void
    {
        $this->cancelled[] = ['key' => $credentials->keyId, 'id' => $linkId];
    }

    public function check(RazorpayCredentials $credentials): void
    {
        if ($this->rejectKeys) {
            throw new RazorpayError('Authentication failed.');
        }
    }
}
