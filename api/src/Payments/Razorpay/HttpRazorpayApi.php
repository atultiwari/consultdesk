<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Razorpay over HTTPS with basic auth (key id and secret). https://razorpay.com/docs/api/payments/payment-links/
 */
final class HttpRazorpayApi implements RazorpayApi
{
    private const BASE = 'https://api.razorpay.com/v1';
    private const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly ClientInterface $http) {}

    public function createPaymentLink(RazorpayCredentials $credentials, PaymentLinkRequest $request): PaymentLink
    {
        $customer = array_filter([
            'name' => $request->customerName,
            'email' => $request->customerEmail,
            'contact' => $request->customerPhone,
        ], static fn(?string $v): bool => $v !== null && $v !== '');
        $body = $this->send($credentials, 'POST', '/payment_links', [
            'amount' => $request->amountMinor,
            'currency' => $request->currency,
            'accept_partial' => false,
            'reference_id' => $request->reference,
            'description' => mb_substr($request->description, 0, 2048),
            'customer' => $customer,
            // ConsultDesk sends its own emails; Razorpay would duplicate them.
            'notify' => ['sms' => false, 'email' => false],
            'reminder_enable' => false,
            'expire_by' => $request->expireBy->getTimestamp(),
            'callback_url' => $request->callbackUrl,
            'callback_method' => 'get',
            'notes' => ['booking_ref' => $request->reference],
        ]);
        $id = $body['id'] ?? null;
        $url = $body['short_url'] ?? null;
        if (!is_string($id) || !is_string($url) || !str_starts_with($url, 'https://')) {
            throw new RazorpayError('Razorpay returned an unexpected answer when creating a payment link.');
        }

        return new PaymentLink($id, $url);
    }

    public function cancelPaymentLink(RazorpayCredentials $credentials, string $linkId): void
    {
        $this->send($credentials, 'POST', '/payment_links/' . rawurlencode($linkId) . '/cancel');
    }

    public function check(RazorpayCredentials $credentials): void
    {
        $this->send($credentials, 'GET', '/payment_links?count=1');
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function send(RazorpayCredentials $credentials, string $method, string $path, ?array $json = null): array
    {
        $options = ['auth' => [$credentials->keyId, $credentials->keySecret], 'timeout' => self::TIMEOUT_SECONDS, 'http_errors' => true];
        if ($json !== null) {
            $options['json'] = $json;
        }
        try {
            $response = $this->http->request($method, self::BASE . $path, $options);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $detail = json_decode((string) $e->getResponse()?->getBody(), true);
            $reason = is_array($detail) && is_string($detail['error']['description'] ?? null) ? $detail['error']['description'] : 'request failed';
            // The HTTP status is the error code, so callers can tell "refused" (4xx) from "unreachable".
            throw new RazorpayError($status === 401 ? 'Razorpay did not accept these keys.' : sprintf('Razorpay said: %s', $reason), $status ?? 0, $e);
        } catch (GuzzleException $e) {
            throw new RazorpayError('Razorpay could not be reached. Try again in a minute.', 0, $e);
        }
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
