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
use ConsultDesk\Infra\Db;
use ConsultDesk\Notify\Money;
use DateTimeImmutable;
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
    /** Links close this long before the hold ends, so a payment never lands just after it. */
    private const CLOSE_EARLY_SECONDS = 90;
    private const SQL = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Db $db,
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
     *
     * @param bool $retry a second attempt (e.g. after a timeout), which needs a fresh reference
     */
    public function ensureLink(BookingView $booking, bool $retry = false): ?string
    {
        if ($booking->gatewayUrl !== null) {
            return $booking->gatewayUrl;
        }
        $now = $this->clock->now();
        $closes = $booking->holdExpiresAt?->modify(sprintf('-%d seconds', self::CLOSE_EARLY_SECONDS));
        if ($booking->paymentMethod !== PaymentMethod::RazorpayLink || $booking->status !== BookingStatus::Held
            || $closes === null || $closes->getTimestamp() - $now->getTimestamp() < self::MIN_LINK_MINUTES * 60) {
            return null;
        }
        $credentials = $this->keys->forProvider($booking->providerId);
        if ($credentials === null) {
            return null;
        }

        // The booking row stays locked while the link is made, so two requests can't make two links.
        return $this->db->transaction(function (PDO $pdo) use ($booking, $credentials, $closes, $retry): ?string {
            $lock = $pdo->prepare('SELECT gateway_url FROM bookings WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $booking->id]);
            $existing = $lock->fetchColumn();
            if (is_string($existing) && $existing !== '') {
                return $existing;
            }
            $link = $this->createLink($booking, $credentials, $closes, $retry);
            if ($link === null) {
                return null;
            }
            $pdo->prepare('UPDATE bookings SET gateway_ref = :ref, gateway_url = :url, gateway_key_id = :key WHERE id = :id')
                ->execute(['ref' => $link->id, 'url' => $link->shortUrl, 'key' => $credentials->keyId, 'id' => $booking->id]);

            return $link->shortUrl;
        });
    }

    private function createLink(BookingView $booking, RazorpayCredentials $credentials, DateTimeImmutable $closes, bool $retry): ?PaymentLink
    {
        $request = fn(?string $phone): PaymentLinkRequest => new PaymentLinkRequest(
            amountMinor: $booking->amountMinor,
            currency: $booking->currency,
            // A retry may follow a timeout after Razorpay already made a link with the plain reference.
            reference: $retry ? sprintf('%s-%s', $booking->ref, base_convert((string) time(), 10, 36)) : $booking->ref,
            description: sprintf('%s with %s, %s (%s)', $booking->serviceTitle, $booking->providerName, $booking->slot->start->setTimezone($booking->displayTimezone())->format('D j M, g:i a'), $booking->ref),
            customerName: $booking->customerName,
            customerEmail: $booking->customerEmail,
            customerPhone: $phone,
            expireBy: $closes,
            callbackUrl: sprintf('%s/b/%s?paid=1', $this->appUrl, rawurlencode($booking->ref)),
        );
        $phone = $booking->customerPhone === null ? null : preg_replace('/[^\d+]/', '', $booking->customerPhone);

        try {
            try {
                return $this->api->createPaymentLink($credentials, $request($phone));
            } catch (RazorpayError $e) {
                // Razorpay is strict about phone numbers; the email alone is enough to pay.
                if ($e->getCode() !== 400 || $phone === null) {
                    throw $e;
                }

                return $this->api->createPaymentLink($credentials, $request(null));
            }
        } catch (RazorpayError $e) {
            error_log(sprintf('ConsultDesk: no payment link for %s: %s', $booking->ref, $e->getMessage()));

            return null;
        }
    }

    /**
     * Handles a webhook delivery. Each event is processed once; unknown links, other events and
     * wrong amounts are recorded and otherwise ignored.
     *
     * @throws InvalidSignature
     */
    public function handleWebhook(string $raw, string $signature): void
    {
        $account = $this->signer($raw, $signature) ?? throw new InvalidSignature();
        $event = json_decode($raw, true, 32);
        if (!is_array($event)) {
            return;
        }
        $type = is_string($event['event'] ?? null) ? $event['event'] : 'unknown';
        // Keyed on the signed body (the event id header is not signed); Razorpay retries send it unchanged.
        $key = hash('sha256', $raw);
        if (!$this->recordEvent($key, $type, $raw)) {
            return; // already handled
        }
        if ($type === 'payment_link.paid') {
            $this->handlePaid($event, $account);
        }
        // Marked only once handled: if handling failed, Razorpay's retry gets another go.
        $this->markProcessed($key);
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private function handlePaid(array $event, RazorpayCredentials $account): void
    {
        $link = $event['payload']['payment_link']['entity'] ?? [];
        $payment = $event['payload']['payment']['entity'] ?? [];
        $booking = $this->bookingFor(is_string($link['id'] ?? null) ? $link['id'] : '', is_string($link['reference_id'] ?? null) ? $link['reference_id'] : '');
        if ($booking === null || $this->accountOf($booking)?->keyId !== $account->keyId) {
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
        $booking = $this->bookingFor($linkId, $reference);
        $booking = $booking?->ref === $ref ? $booking : null;
        $credentials = $booking === null ? null : $this->accountOf($booking);
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
        $credentials = $this->accountOf($booking);
        if ($credentials === null) {
            return;
        }
        try {
            $this->api->cancelPaymentLink($credentials, $booking->gatewayRef);
        } catch (RazorpayError $e) {
            if (in_array($e->getCode(), [400, 404], true)) {
                return; // already paid, cancelled or expired at Razorpay
            }
            throw $e; // e.g. rate limited or unreachable: the outbox tries again
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
     * The account that made the booking's link: only it may vouch for the payment, even if the keys
     * have changed since.
     */
    private function accountOf(BookingView $booking): ?RazorpayCredentials
    {
        return $booking->gatewayKeyId !== null ? $this->keys->byKeyId($booking->gatewayKeyId) : $this->keys->forProvider($booking->providerId);
    }

    /**
     * @param string $reference the link's reference: the booking ref, or "ref-xxxx" for a retried link
     */
    private function bookingFor(string $linkId, string $reference): ?BookingView
    {
        $ref = explode('-', $reference);
        if ($linkId === '' || count($ref) < 2) {
            return null;
        }
        $booking = $this->views->findByRef($ref[0] . '-' . $ref[1]);

        return $booking !== null && $booking->gatewayRef !== null && hash_equals($booking->gatewayRef, $linkId) ? $booking : null;
    }

    /**
     * @return bool false when this event was already handled
     */
    private function recordEvent(string $key, string $type, string $raw): bool
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT IGNORE INTO payment_events (gateway, event_id, event_type, payload, received_at)
             VALUES ('razorpay', :id, :type, :payload, :now)",
        )->execute([
            'id' => $key,
            'type' => mb_substr($type, 0, 64),
            'payload' => is_array(json_decode($raw, true)) ? $raw : '{}',
            'now' => $this->clock->now()->format(self::SQL),
        ]);
        $done = $pdo->prepare("SELECT processed_at IS NOT NULL FROM payment_events WHERE gateway = 'razorpay' AND event_id = :id");
        $done->execute(['id' => $key]);

        return !(bool) $done->fetchColumn();
    }

    private function markProcessed(string $key): void
    {
        $this->db->pdo()->prepare("UPDATE payment_events SET processed_at = :now WHERE gateway = 'razorpay' AND event_id = :id")
            ->execute(['now' => $this->clock->now()->format(self::SQL), 'id' => $key]);
    }
}
