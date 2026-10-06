<?php

declare(strict_types=1);

namespace ConsultDesk\Payments\Razorpay;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Notify\Money;
use PDO;

/**
 * Online payment with Razorpay Payment Links (docs/PLAN.md §6.4).
 *
 * Each booking gets its own link for its exact amount, which expires with the hold. The booking is
 * confirmed by whichever arrives first: Razorpay's signed webhook, or the signed redirect when the
 * customer comes back. Both must be signed by the account the booking is paid into.
 */
final class RazorpayCheckout
{
    /** Razorpay needs a link to stay open at least 15 minutes; one more for clock drift. */
    private const MIN_LINK_MINUTES = 16;
    private const SQL = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PDO $pdo,
        private readonly GatewayKeys $keys,
        private readonly RazorpayApi $api,
        private readonly BookingService $bookings,
        private readonly BookingViewRepository $views,
        private readonly Clock $clock,
        private readonly string $appUrl,
    ) {}

    /**
     * The booking's payment link, made now if it does not exist yet. Null when there can't be one
     * (not an online booking, no longer payable, not enough time left, or Razorpay is unreachable).
     */
    public function ensureLink(BookingView $booking): ?string
    {
        if ($booking->gatewayUrl !== null) {
            return $booking->gatewayUrl;
        }
        $now = $this->clock->now();
        if ($booking->paymentMethod !== PaymentMethod::RazorpayLink || $booking->status !== BookingStatus::Held
            || $booking->holdExpiresAt === null || $booking->holdExpiresAt->getTimestamp() - $now->getTimestamp() < self::MIN_LINK_MINUTES * 60) {
            return null;
        }
        $credentials = $this->keys->forProvider($booking->providerId);
        if ($credentials === null) {
            return null;
        }

        try {
            $link = $this->api->createPaymentLink($credentials, new PaymentLinkRequest(
                amountMinor: $booking->amountMinor,
                currency: $booking->currency,
                reference: $booking->ref,
                description: sprintf('%s with %s, %s (%s)', $booking->serviceTitle, $booking->providerName, $booking->slot->start->setTimezone($booking->displayTimezone())->format('D j M, g:i a'), $booking->ref),
                customerName: $booking->customerName,
                customerEmail: $booking->customerEmail,
                customerPhone: $booking->customerPhone,
                expireBy: $booking->holdExpiresAt,
                callbackUrl: sprintf('%s/b/%s?paid=1', $this->appUrl, rawurlencode($booking->ref)),
            ));
        } catch (RazorpayError $e) {
            error_log(sprintf('ConsultDesk: no payment link for %s: %s', $booking->ref, $e->getMessage()));

            return null;
        }

        $this->pdo->prepare('UPDATE bookings SET gateway_ref = :ref, gateway_url = :url WHERE id = :id AND gateway_ref IS NULL')
            ->execute(['ref' => $link->id, 'url' => $link->shortUrl, 'id' => $booking->id]);

        return $this->views->findById($booking->id)?->gatewayUrl;
    }

    /**
     * Handles a webhook delivery. Each event is processed once; unknown links, other events and
     * wrong amounts are recorded and otherwise ignored.
     *
     * @throws InvalidSignature
     */
    public function handleWebhook(string $raw, string $signature, string $eventId): void
    {
        $account = $this->signer($raw, $signature) ?? throw new InvalidSignature();
        $event = json_decode($raw, true, 32);
        if (!is_array($event)) {
            return;
        }
        $type = is_string($event['event'] ?? null) ? $event['event'] : 'unknown';
        if (!$this->recordEvent($eventId !== '' ? $eventId : hash('sha256', $raw), $type, $raw)) {
            return; // a retry of an event already handled
        }
        if ($type !== 'payment_link.paid') {
            return;
        }

        $link = $event['payload']['payment_link']['entity'] ?? [];
        $payment = $event['payload']['payment']['entity'] ?? [];
        $booking = $this->bookingFor(is_string($link['id'] ?? null) ? $link['id'] : '', is_string($link['reference_id'] ?? null) ? $link['reference_id'] : '');
        if ($booking === null || !$this->paidInto($booking, $account)) {
            return;
        }
        $paid = $link['amount_paid'] ?? $payment['amount'] ?? null;
        $currency = $link['currency'] ?? $payment['currency'] ?? null;
        if ($paid !== $booking->amountMinor || $currency !== $booking->currency || !is_string($payment['id'] ?? null)) {
            error_log(sprintf('ConsultDesk: payment for %s did not match (%s %s, expected %s)', $booking->ref, var_export($paid, true), var_export($currency, true), Money::format($booking->amountMinor, $booking->currency)));

            return;
        }

        $this->bookings->confirmPaid($booking->id, $payment['id'], Actor::webhook());
    }

    /**
     * The customer is back from Razorpay with its signed redirect parameters.
     *
     * @param array<string, string> $params razorpay_payment_id, razorpay_payment_link_id, razorpay_payment_link_reference_id,
     *                                      razorpay_payment_link_status, razorpay_signature
     *
     * @return BookingStatus|null the booking's status afterwards, or null when the redirect is not genuine
     */
    public function handleReturn(string $ref, array $params): ?BookingStatus
    {
        $linkId = $params['razorpay_payment_link_id'] ?? '';
        $reference = $params['razorpay_payment_link_reference_id'] ?? '';
        $status = $params['razorpay_payment_link_status'] ?? '';
        $paymentId = $params['razorpay_payment_id'] ?? '';
        $booking = $reference === $ref ? $this->bookingFor($linkId, $ref) : null;
        $credentials = $booking === null ? null : $this->keys->forProvider($booking->providerId);
        if ($booking === null || $credentials === null) {
            return null;
        }
        $expected = hash_hmac('sha256', implode('|', [$linkId, $reference, $status, $paymentId]), $credentials->keySecret);
        if (!hash_equals($expected, $params['razorpay_signature'] ?? '')) {
            return null;
        }
        if ($status === 'paid' && $paymentId !== '') {
            $this->bookings->confirmPaid($booking->id, $paymentId, Actor::customer());
        }

        return $this->views->findById($booking->id)?->status;
    }

    /**
     * Cancels an unpaid link at Razorpay once its booking can no longer be paid for.
     *
     * @throws RazorpayError when Razorpay can't be reached (the job is retried)
     */
    public function cancelLink(int $bookingId): void
    {
        $booking = $this->views->findById($bookingId);
        if ($booking === null || $booking->gatewayRef === null || $booking->status === BookingStatus::Confirmed) {
            return;
        }
        $credentials = $this->keys->forProvider($booking->providerId);
        if ($credentials === null) {
            return;
        }
        try {
            $this->api->cancelPaymentLink($credentials, $booking->gatewayRef);
        } catch (RazorpayError $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                return; // already paid, cancelled or expired at Razorpay
            }
            throw $e;
        }
    }

    /**
     * The account whose webhook secret signed this body, if any.
     */
    private function signer(string $raw, string $signature): ?RazorpayCredentials
    {
        foreach ($this->keys->all() as $account) {
            if ($account->webhookSecret !== null && hash_equals(hash_hmac('sha256', $raw, $account->webhookSecret), $signature)) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Only the account the booking is paid into may vouch for its payment.
     */
    private function paidInto(BookingView $booking, RazorpayCredentials $account): bool
    {
        return $this->keys->forProvider($booking->providerId)?->keyId === $account->keyId;
    }

    private function bookingFor(string $linkId, string $ref): ?BookingView
    {
        if ($linkId === '' || $ref === '') {
            return null;
        }
        $booking = $this->views->findByRef($ref);

        return $booking !== null && $booking->gatewayRef !== null && hash_equals($booking->gatewayRef, $linkId) ? $booking : null;
    }

    /**
     * @return bool false when this event was already recorded
     */
    private function recordEvent(string $eventId, string $type, string $raw): bool
    {
        $statement = $this->pdo->prepare(
            "INSERT IGNORE INTO payment_events (gateway, event_id, event_type, payload, received_at, processed_at)
             VALUES ('razorpay', :id, :type, :payload, :now, :processed)",
        );
        $now = $this->clock->now()->format(self::SQL);
        $statement->execute(['id' => mb_substr($eventId, 0, 100), 'type' => mb_substr($type, 0, 64), 'payload' => is_array(json_decode($raw, true)) ? $raw : '{}', 'now' => $now, 'processed' => $now]);

        return $statement->rowCount() === 1;
    }
}
