<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\SiteSetup;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Provider profiles, contact and UPI details, and booking rules. Providers edit their own;
 * only staff create providers or change the slug, visibility and order.
 */
final class AdminProviderActions
{
    public const SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';
    private const STAFF_ONLY = ['slug', 'active', 'sort_order'];
    /** Where money and messages go: audited with old and new values, so a change can be traced. */
    private const TRACED = ['upi_vpa', 'upi_payee_name', 'notify_email', 'whatsapp'];
    public const UPI_VPA = '/^[A-Za-z0-9._-]{2,256}@[A-Za-z][A-Za-z0-9]{1,63}$/';
    /** Rule => [min, max]. Matches BookingRules. */
    private const RULE_LIMITS = [
        'min_notice_min' => [0, 525_600],
        'horizon_days' => [1, 365],
        'buffer_before' => [0, 240],
        'buffer_after' => [0, 240],
        'slot_interval' => [5, 1440],
        'max_per_day' => [1, 1000],
    ];

    public function __construct(
        private readonly ProviderSettings $providers,
        private readonly AuditLog $audit,
        private readonly ?SiteSetup $setup = null,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        return JsonResponse::success($response, $this->providers->all(AdminScope::user($request)->providerScope()));
    }

    public function create(Request $request, Response $response): Response
    {
        $user = AdminScope::user($request);
        if (!$user->isStaff()) {
            throw ApiException::forbidden();
        }
        if ($this->setup?->isSingle() === true && $this->providers->all(null) !== []) {
            throw new ApiException(409, 'single_teacher_site', 'This site is set up for one teacher. Switch it to several teachers first (owner: Set up your site).');
        }
        $input = new Input(JsonInput::decode($request));
        $values = $this->read($input, creating: true);
        if (isset($values['slug']) && $this->providers->slugTaken((string) $values['slug'])) {
            $input->reject('slug', 'Another provider already uses this address.');
        }
        $input->assertValid();

        $id = $this->providers->create($values);
        $this->audit->record(Actor::user($user->id), 'admin.provider_created', 'provider', $id, ['slug' => $values['slug']]);

        return JsonResponse::success($response, $this->providers->find($id), status: 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $before = AdminScope::provider($this->providers, $request, $id);
        $user = AdminScope::user($request);
        $body = JsonInput::decode($request);
        if (!$user->isStaff() && array_intersect(self::STAFF_ONLY, array_keys($body)) !== []) {
            throw ApiException::forbidden();
        }
        $input = new Input($body);
        $values = $this->read($input, creating: false);
        if (isset($values['slug']) && $this->providers->slugTaken((string) $values['slug'], $id)) {
            $input->reject('slug', 'Another provider already uses this address.');
        }
        $input->assertValid();

        $this->providers->update($id, $values);
        if ($values !== []) {
            $this->audit->record(Actor::user($user->id), 'admin.provider_updated', 'provider', $id, [
                'fields' => array_keys($values),
                'changes' => self::tracedChanges($before, $values),
            ]);
        }

        return JsonResponse::success($response, $this->providers->find($id));
    }

    /**
     * @param array<string, mixed>       $before
     * @param array<string, scalar|null> $values
     *
     * @return array<string, array{from: mixed, to: scalar|null}>
     */
    private static function tracedChanges(array $before, array $values): array
    {
        $changes = [];
        foreach (array_intersect_key($values, array_flip(self::TRACED)) as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
            }
        }

        return $changes;
    }

    /**
     * Reads the fields that were sent (all required ones when creating), in column order.
     *
     * @return array<string, scalar|null>
     */
    private function read(Input $input, bool $creating): array
    {
        $values = [];
        $wants = static fn(string $field): bool => $creating || $input->has($field);

        if ($wants('slug')) {
            $values['slug'] = $input->string('slug', max: 64);
            if ($values['slug'] !== null && preg_match(self::SLUG, $values['slug']) !== 1) {
                $input->reject('slug', 'Use lowercase letters, numbers and dashes.');
            }
        }
        if ($wants('name')) {
            $values['name'] = $input->personName('name', 120);
        }
        foreach (['title' => 160, 'bio' => 5000] as $field => $max) {
            if ($input->has($field)) {
                $values[$field] = $input->string($field, required: false, max: $max);
            }
        }
        if ($input->has('whatsapp')) {
            $values['whatsapp'] = $input->phone('whatsapp', required: false);
        }
        if ($input->has('notify_email')) {
            $email = $input->email('notify_email', required: false);
            $values['notify_email'] = $email === null ? null : strtolower($email);
        }
        if ($input->has('upi_vpa')) {
            $values['upi_vpa'] = $input->string('upi_vpa', required: false, max: 100);
            if ($values['upi_vpa'] !== null && preg_match(self::UPI_VPA, $values['upi_vpa']) !== 1) {
                $input->reject('upi_vpa', 'Enter a UPI ID like name@bank.');
            }
        }
        if ($input->has('upi_payee_name')) {
            $values['upi_payee_name'] = $input->string('upi_payee_name', required: false, max: 100);
        }
        if ($wants('timezone')) {
            $values['timezone'] = $input->timezone('timezone');
        }
        if ($input->has('active')) {
            $values['active'] = $input->bool('active', required: true);
        }
        if ($input->has('sort_order')) {
            $values['sort_order'] = $input->int('sort_order', min: -10_000, max: 10_000);
        }
        if ($input->has('rules')) {
            $values = [...$values, ...$this->readRules($input->nested('rules'))];
        }

        return $values;
    }

    /**
     * @return array<string, int|null>
     */
    private function readRules(Input $rules): array
    {
        $values = [];
        foreach (self::RULE_LIMITS as $rule => [$min, $max]) {
            if (!$rules->has($rule)) {
                continue;
            }
            // A null daily cap means "no limit"; every other rule needs a number.
            $values[$rule] = $rules->int($rule, required: $rule !== 'max_per_day', min: $min, max: $max);
        }

        return $values;
    }
}
