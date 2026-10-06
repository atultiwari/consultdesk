<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Payments\Razorpay\InvalidSignature;
use ConsultDesk\Payments\Razorpay\RazorpayCheckout;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/webhooks/razorpay. The raw body is checked against the X-Razorpay-Signature HMAC of a
 * configured account before anything in it is trusted.
 */
final class RazorpayWebhookAction
{
    private const MAX_BYTES = 256 * 1024;

    public function __construct(private readonly ?RazorpayCheckout $checkout) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $checkout = $this->checkout ?? throw ApiException::notFound();
        $raw = $request->getBody()->read(self::MAX_BYTES + 1);
        if (strlen($raw) > self::MAX_BYTES) {
            throw new ApiException(413, 'payload_too_large', 'Too large.');
        }

        try {
            $checkout->handleWebhook($raw, $request->getHeaderLine('X-Razorpay-Signature'), $request->getHeaderLine('X-Razorpay-Event-Id'));
        } catch (InvalidSignature) {
            throw new ApiException(400, 'bad_signature', 'Signature check failed.');
        }

        return JsonResponse::success($response, ['ok' => true]);
    }
}
