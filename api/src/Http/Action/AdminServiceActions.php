<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\ServiceSettings;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Catalog\QuestionSet;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A provider's services and their intake questions (the question builder).
 */
final class AdminServiceActions
{
    private const PAID_METHODS = ['upi', 'razorpay_link'];
    private const MAX_PRICE_MINOR = 100_000_000;

    public function __construct(
        private readonly ProviderSettings $providers,
        private readonly ServiceSettings $services,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function list(Request $request, Response $response, array $args): Response
    {
        $provider = AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0));

        return JsonResponse::success($response, $this->services->forProvider((int) $provider['id']));
    }

    /**
     * The order sessions are listed in, on the booking site and here.
     *
     * @param array<string, string> $args
     */
    public function reorder(Request $request, Response $response, array $args): Response
    {
        $providerId = (int) AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0))['id'];
        $input = new Input(JsonInput::decode($request));
        $ids = $input->list('ids', required: true, max: 200) ?? [];
        $input->assertValid();
        $ids = array_values(array_map('intval', array_filter($ids, 'is_numeric')));
        if (!$this->services->reorder($providerId, $ids)) {
            $input->reject('ids', 'Send every one of this teacher’s sessions, once each.');
            $input->assertValid();
        }
        $this->audit->record(Actor::user(AdminScope::user($request)->id), 'admin.services_reordered', 'provider', $providerId, ['ids' => $ids]);

        return JsonResponse::success($response, $this->services->forProvider($providerId));
    }

    /**
     * @param array<string, string> $args
     */
    public function create(Request $request, Response $response, array $args): Response
    {
        $providerId = (int) AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0))['id'];
        $input = new Input(JsonInput::decode($request));
        $values = $this->read($input, null);
        if (isset($values['slug']) && $this->services->slugTaken($providerId, (string) $values['slug'])) {
            $input->reject('slug', 'Another of this provider\'s sessions already uses this address.');
        }
        $input->assertValid();

        $id = $this->services->create($providerId, $values);
        $this->audit->record(Actor::user(AdminScope::user($request)->id), 'admin.service_created', 'service', $id, ['slug' => $values['slug']]);

        return JsonResponse::success($response, $this->services->find($id), status: 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $service = $this->services->find((int) ($args['id'] ?? 0)) ?? throw ApiException::notFound();
        AdminScope::provider($this->providers, $request, (int) $service['provider_id']);
        $input = new Input(JsonInput::decode($request));
        $values = $this->read($input, $service);
        if (isset($values['slug']) && $this->services->slugTaken((int) $service['provider_id'], (string) $values['slug'], (int) $service['id'])) {
            $input->reject('slug', 'Another of this provider\'s sessions already uses this address.');
        }
        $input->assertValid();

        $this->services->update((int) $service['id'], $values);
        if ($values !== []) {
            $this->audit->record(Actor::user(AdminScope::user($request)->id), 'admin.service_updated', 'service', (int) $service['id'], ['fields' => array_keys($values)]);
        }

        return JsonResponse::success($response, $this->services->find((int) $service['id']));
    }

    /**
     * Reads the sent fields (all required ones when $existing is null), in column order.
     *
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function read(Input $input, ?array $existing): array
    {
        $values = [];
        $wants = static fn(string $field): bool => $existing === null || $input->has($field);

        if ($wants('slug')) {
            $values['slug'] = $input->string('slug', max: 64);
            if ($values['slug'] !== null && preg_match(AdminProviderActions::SLUG, $values['slug']) !== 1) {
                $input->reject('slug', 'Use lowercase letters, numbers and dashes.');
            }
        }
        if ($wants('title')) {
            $values['title'] = $input->string('title', max: 160);
        }
        foreach (['tagline' => 255, 'description' => 5000, 'audience' => 160] as $field => $max) {
            if ($input->has($field)) {
                $values[$field] = $input->string($field, required: false, max: $max);
            }
        }
        if ($wants('duration_min')) {
            $values['duration_min'] = $input->int('duration_min', min: 5, max: 1440);
        }
        if ($wants('price_minor')) {
            $values['price_minor'] = $input->int('price_minor', min: 0, max: self::MAX_PRICE_MINOR);
        }
        if ($input->has('highlight')) {
            $highlight = $input->string('highlight', required: false, max: 24);
            if ($highlight !== null && preg_match('/[<>]|:\/\/|www\./i', $highlight) === 1) {
                $input->reject('highlight', 'Use a few plain words, e.g. Most popular.');
            }
            $values['highlight'] = $highlight;
        }
        if ($input->has('requires_approval')) {
            $values['requires_approval'] = $input->bool('requires_approval', required: true);
        }
        $methods = $this->paymentMethods($input, $values['price_minor'] ?? $existing['price_minor'] ?? 0, $existing);
        if ($methods !== null) {
            $values['payment_methods'] = $methods;
        }
        if ($input->has('questions')) {
            $values['questions'] = $this->questions($input);
        }
        if ($input->has('active')) {
            $values['active'] = $input->bool('active', required: true);
        }
        if ($input->has('sort_order')) {
            $values['sort_order'] = $input->int('sort_order', min: -10_000, max: 10_000);
        }

        return $values;
    }

    /**
     * Free sessions take only "free"; paid ones at least one paid method (UPI when nothing else is
     * chosen, e.g. when a free session becomes paid). Returns null when nothing needs saving.
     *
     * @param array<string, mixed>|null $existing
     *
     * @return list<string>|null
     */
    private function paymentMethods(Input $input, mixed $price, ?array $existing): ?array
    {
        if (!$input->has('payment_methods') && $existing !== null && !$input->has('price_minor')) {
            return null;
        }
        $sent = $input->list('payment_methods', max: 3);
        if ($sent !== null) {
            foreach ($sent as $method) {
                if (!is_string($method) || !in_array($method, [...self::PAID_METHODS, 'free'], true)) {
                    $input->reject('payment_methods', 'Unknown payment method.');

                    return null;
                }
            }
        }
        if (!is_int($price)) {
            return null;
        }
        if ($price === 0) {
            return ['free'];
        }
        $requested = $sent ?? (is_array($existing['payment_methods'] ?? null) ? $existing['payment_methods'] : []);
        $methods = array_values(array_intersect(self::PAID_METHODS, $requested));
        if ($methods === [] && $sent === null) {
            return ['upi'];
        }
        if ($methods === []) {
            $input->reject('payment_methods', 'Choose at least one way to pay for a paid session.');
        }

        return $methods;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questions(Input $input): array
    {
        $raw = $input->list('questions', required: true, max: QuestionSet::MAX_QUESTIONS);
        if ($raw === null) {
            return [];
        }
        try {
            return QuestionSet::fromArray($raw)->toArray();
        } catch (InvalidArgumentException $e) {
            $input->reject('questions', $e->getMessage());

            return [];
        }
    }
}
