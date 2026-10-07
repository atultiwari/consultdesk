<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\Settings;
use ConsultDesk\Payments\PaymentSwitches;
use ConsultDesk\Payments\Razorpay\GatewayKeys;
use ConsultDesk\Payments\Razorpay\RazorpayApi;
use ConsultDesk\Payments\Razorpay\RazorpayError;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The owner's Payments page: which ways to pay are on, the organisation's Razorpay keys, and any
 * provider's own Razorpay account. Secrets are write-only; a webhook secret is shown once, when made.
 */
final class AdminPaymentActions
{
    private const KEY_ID = '/^rzp_(test|live)_[A-Za-z0-9]{14,}$/';

    public function __construct(
        private readonly Settings $settings,
        private readonly GatewayKeys $keys,
        private readonly ?RazorpayApi $api,
        private readonly ProviderSettings $providers,
        private readonly AuditLog $audit,
        private readonly PDO $pdo,
        private readonly string $appUrl,
        /** Live keys stay off until real-money payments are signed off (Phase 7 is test mode only). */
        private readonly bool $testKeysOnly = true,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        AdminScope::owner($request);

        return JsonResponse::success($response, $this->state());
    }

    public function saveMethods(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $input = new Input(JsonInput::decode($request));
        $switches = new PaymentSwitches((bool) $input->bool('upi_enabled', required: true), (bool) $input->bool('razorpay_enabled', required: true));
        $input->assertValid();

        $this->settings->put(PaymentSwitches::KEY, $switches->toArray());
        $this->audit->record(Actor::user($owner->id), 'admin.payment_methods_changed', 'settings', null, $switches->toArray());

        return JsonResponse::success($response, $this->state());
    }

    public function saveOrgKeys(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $webhookSecret = $this->saveKeys($request, null);
        $this->audit->record(Actor::user($owner->id), 'admin.razorpay_keys_saved', 'settings', null, ['key_id' => $this->keys->find(null)?->keyId]);

        return JsonResponse::success($response, $this->state($webhookSecret));
    }

    public function check(Request $request, Response $response): Response
    {
        AdminScope::owner($request);
        $this->checkAccount(null);

        return JsonResponse::success($response, $this->state());
    }

    public function newWebhookSecret(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $this->assertSavedHere();
        $secret = self::randomSecret();
        $this->keys->setWebhookSecret(null, $secret);
        $this->audit->record(Actor::user($owner->id), 'admin.razorpay_webhook_secret_changed', 'settings', null);

        return JsonResponse::success($response, $this->state($secret));
    }

    /**
     * Ticks "Razorpay payment link" on every session that can take it: paid, in INR and not needing
     * approval. Sessions keep their other ways to pay.
     */
    public function offerEverywhere(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $statement = $this->pdo->prepare(
            "UPDATE services SET payment_methods = JSON_ARRAY_APPEND(payment_methods, '$', 'razorpay_link')
             WHERE price_minor > 0 AND currency = 'INR' AND requires_approval = 0
               AND NOT JSON_CONTAINS(payment_methods, '\"razorpay_link\"')",
        );
        $statement->execute();
        $count = $statement->rowCount();
        $this->audit->record(Actor::user($owner->id), 'admin.razorpay_offered_everywhere', 'settings', null, ['sessions' => $count]);

        return JsonResponse::success($response, ['sessions_updated' => $count]);
    }

    public function removeOrgKeys(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $this->assertSavedHere();
        $this->assertNoOpenLinks(null);
        $this->keys->delete(null);
        $this->audit->record(Actor::user($owner->id), 'admin.razorpay_keys_removed', 'settings', null);

        return JsonResponse::success($response, $this->state());
    }

    /**
     * @param array<string, string> $args
     */
    public function saveProviderKeys(Request $request, Response $response, array $args): Response
    {
        $owner = AdminScope::owner($request);
        $providerId = (int) AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0))['id'];
        $webhookSecret = $this->saveKeys($request, $providerId);
        $keyId = $this->keys->find($providerId)?->keyId;
        $this->audit->record(Actor::user($owner->id), 'admin.razorpay_keys_saved', 'provider', $providerId, ['key_id' => $keyId]);

        return JsonResponse::success($response, ['provider_id' => $providerId, 'key_id' => $keyId, 'webhook_secret' => $webhookSecret]);
    }

    /**
     * @param array<string, string> $args
     */
    public function checkProviderKeys(Request $request, Response $response, array $args): Response
    {
        AdminScope::owner($request);
        $providerId = (int) AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0))['id'];
        $this->checkAccount($providerId);

        return JsonResponse::success($response, ['ok' => true]);
    }

    /**
     * @param array<string, string> $args
     */
    public function removeProviderKeys(Request $request, Response $response, array $args): Response
    {
        $owner = AdminScope::owner($request);
        $providerId = (int) AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0))['id'];
        $this->assertNoOpenLinks($providerId);
        $this->keys->delete($providerId);
        $this->audit->record(Actor::user($owner->id), 'admin.razorpay_keys_removed', 'provider', $providerId);

        return JsonResponse::success($response, ['ok' => true]);
    }

    /**
     * Validates and stores keys. A new account gets a fresh webhook secret, returned to show once;
     * re-saving the same account keeps its secret (and returns null), so its webhook keeps working.
     */
    private function saveKeys(Request $request, ?int $providerId): ?string
    {
        $input = new Input(JsonInput::decode($request));
        $keyId = $input->string('key_id', max: 64);
        $secret = $input->secret('key_secret', max: 128);
        if ($keyId !== null && preg_match(self::KEY_ID, $keyId) !== 1) {
            $input->reject('key_id', 'Paste the Key ID from Razorpay → Settings → API Keys; it starts with rzp_test_.');
        } elseif ($keyId !== null && $this->testKeysOnly && !str_starts_with($keyId, 'rzp_test_')) {
            $input->reject('key_id', 'Only Test Mode keys (rzp_test_…) can be used for now.');
        }
        if ($secret !== null && (strlen($secret) < 16 || preg_match('/\s/', $secret) === 1)) {
            $input->reject('key_secret', 'Paste the Key Secret exactly as Razorpay showed it.');
        }
        $input->assertValid();

        $current = $this->keys->find($providerId);
        if ($current !== null && $current->keyId !== $keyId) {
            $this->assertNoOpenLinks($providerId);
        }
        $keepSecret = $current !== null && $current->keyId === $keyId && $current->webhookSecret !== null;
        $webhookSecret = $keepSecret ? null : self::randomSecret();
        $this->keys->save($providerId, (string) $keyId, (string) $secret, $webhookSecret);

        return $webhookSecret;
    }

    /**
     * Customers still holding payment links from this account must be able to finish paying.
     */
    private function assertNoOpenLinks(?int $providerId): void
    {
        $open = $this->keys->openLinks($providerId);
        if ($open > 0) {
            throw new ApiException(409, 'links_open', sprintf(
                '%d %s waiting to be paid with these keys. Try again in about half an hour, once %s.',
                $open,
                $open === 1 ? 'customer is' : 'customers are',
                $open === 1 ? 'that hold has ended' : 'those holds have ended',
            ));
        }
    }

    /**
     * Keys from .env are changed in .env; only keys saved here can be rotated or removed here.
     */
    private function assertSavedHere(): void
    {
        $source = $this->keys->orgSource();
        if ($source === null) {
            throw self::notConfigured();
        }
        if ($source === 'env') {
            throw new ApiException(409, 'env_keys', 'These keys come from the server’s .env file. Change them there, or save your own keys here to use instead.');
        }
    }

    private function checkAccount(?int $providerId): void
    {
        $credentials = $this->keys->find($providerId) ?? throw self::notConfigured();
        $api = $this->api ?? throw self::notConfigured();
        try {
            $api->check($credentials);
        } catch (RazorpayError $e) {
            throw new ApiException(422, 'razorpay_rejected', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function state(?string $newWebhookSecret = null): array
    {
        $org = $this->keys->find(null);
        $razorpay = [
            'configured' => $org !== null,
            'mode' => $org === null ? null : ($org->isTestMode() ? 'test' : 'live'),
            'key_id' => $org?->keyId,
            'source' => $this->keys->orgSource(),
            'env_key_id' => $this->keys->defaults()?->keyId,
            'has_webhook_secret' => $org?->webhookSecret !== null,
            'webhook_url' => $this->appUrl . '/api/webhooks/razorpay',
            'live_allowed' => !$this->testKeysOnly,
        ];
        if ($newWebhookSecret !== null) {
            $razorpay['webhook_secret'] = $newWebhookSecret;
        }

        return [
            'methods' => PaymentSwitches::load($this->settings)->toArray(),
            'razorpay' => $razorpay,
            'overrides' => $this->keys->overrides(),
        ];
    }

    private static function randomSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
    }

    private static function notConfigured(): ApiException
    {
        return new ApiException(409, 'not_configured', 'Add Razorpay keys first.');
    }
}
