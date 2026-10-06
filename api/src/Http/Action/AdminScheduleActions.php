<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\BlockedTimes;
use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\ServiceSettings;
use ConsultDesk\Admin\WeeklyHours;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\Clock;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Weekly availability and blocked times.
 */
final class AdminScheduleActions
{
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';
    private const MAX_RULES = 100;
    private const MAX_BLOCK_DAYS = 366;

    public function __construct(
        private readonly ProviderSettings $providers,
        private readonly ServiceSettings $services,
        private readonly WeeklyHours $hours,
        private readonly BlockedTimes $blocked,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function availability(Request $request, Response $response, array $args): Response
    {
        $provider = AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0));

        return JsonResponse::success($response, $this->hours->forProvider((int) $provider['id']));
    }

    /**
     * Replaces every weekly window at once, so the panel can save the week as one form.
     *
     * @param array<string, string> $args
     */
    public function replaceAvailability(Request $request, Response $response, array $args): Response
    {
        $providerId = (int) AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0))['id'];
        $input = new Input(JsonInput::decode($request));
        $raw = $input->list('rules', required: true, max: self::MAX_RULES) ?? [];
        $serviceIds = array_column($this->services->forProvider($providerId), 'id');

        $rules = [];
        foreach ($raw as $i => $item) {
            $rule = is_array($item) ? $this->rule($item, $serviceIds) : 'Each window needs a weekday, start and end.';
            if (is_string($rule)) {
                $input->reject("rules.{$i}", $rule);
            } else {
                $rules[] = $rule;
            }
        }
        $input->assertValid();

        $this->hours->replace($providerId, $rules);
        $this->audit->record(Actor::user(AdminScope::user($request)->id), 'admin.availability_replaced', 'provider', $providerId, ['windows' => count($rules)]);

        return JsonResponse::success($response, $this->hours->forProvider($providerId));
    }

    public function listBlocked(Request $request, Response $response): Response
    {
        return JsonResponse::success($response, $this->blocked->upcoming(AdminScope::user($request)->providerScope(), $this->clock->now()));
    }

    public function createBlocked(Request $request, Response $response): Response
    {
        $body = JsonInput::decode($request);
        $input = new Input($body);
        if (!array_key_exists('provider_id', $body)) {
            $input->reject('provider_id', 'Choose a provider, or null to close for everyone.');
            $input->assertValid();
        }
        $providerId = $input->int('provider_id', required: false, min: 1);
        $this->assertCanBlock($request, $providerId);
        $start = $input->dateTime('start');
        $end = $input->dateTime('end');
        $allDay = $input->bool('all_day') ?? false;
        $reason = $input->string('reason', required: false, max: 255);
        if ($start !== null && $end !== null && $end <= $start) {
            $input->reject('end', 'Must be after the start.');
        } elseif ($start !== null && $end !== null && $start->diff($end)->days > self::MAX_BLOCK_DAYS) {
            $input->reject('end', sprintf('Block at most %d days at a time.', self::MAX_BLOCK_DAYS));
        }
        $input->assertValid();
        if ($start === null || $end === null) {
            throw ApiException::badRequest();
        }

        $id = $this->blocked->create($providerId, $start, $end, $allDay, $reason);
        $this->audit->record(Actor::user(AdminScope::user($request)->id), 'admin.blocked_created', 'blocked_period', $id, ['provider_id' => $providerId]);

        return JsonResponse::success($response, $this->blocked->find($id), status: 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteBlocked(Request $request, Response $response, array $args): Response
    {
        $block = $this->blocked->find((int) ($args['id'] ?? 0)) ?? throw ApiException::notFound();
        $providerId = $block['provider_id'];
        $this->assertCanBlock($request, is_int($providerId) ? $providerId : null);

        $this->blocked->delete((int) $block['id']);
        $this->audit->record(Actor::user(AdminScope::user($request)->id), 'admin.blocked_deleted', 'blocked_period', (int) $block['id'], [
            'provider_id' => $providerId,
            'start' => $block['start'],
            'end' => $block['end'],
        ]);

        return JsonResponse::success($response, null);
    }

    /**
     * Organisation-wide closures are for staff; a provider's own are for them and staff.
     */
    private function assertCanBlock(Request $request, ?int $providerId): void
    {
        if ($providerId === null) {
            if (!AdminScope::user($request)->isStaff()) {
                throw ApiException::forbidden();
            }

            return;
        }
        AdminScope::provider($this->providers, $request, $providerId);
    }

    /**
     * @param array<array-key, mixed> $item
     * @param list<int>               $serviceIds the provider's own services
     *
     * @return array{weekday: int, start: string, end: string, service_id: ?int}|string the rule, or what is wrong with it
     */
    private function rule(array $item, array $serviceIds): array|string
    {
        $weekday = $item['weekday'] ?? null;
        $start = $item['start'] ?? null;
        $end = $item['end'] ?? null;
        $serviceId = $item['service_id'] ?? null;

        return match (true) {
            !is_int($weekday) || $weekday < 1 || $weekday > 7 => 'Weekday must be 1 (Monday) to 7 (Sunday).',
            !is_string($start) || !is_string($end) || preg_match(self::TIME, $start) !== 1 || preg_match(self::TIME, $end) !== 1 => 'Times must look like 09:30.',
            $end <= $start => 'The end must be after the start.',
            $serviceId !== null && (!is_int($serviceId) || !in_array($serviceId, $serviceIds, true)) => 'That session belongs to another provider.',
            default => ['weekday' => $weekday, 'start' => $start, 'end' => $end, 'service_id' => $serviceId],
        };
    }
}
