<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\SystemStatus;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Infra\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The owner's System panel: health, "Run database updates" and "Retry failed jobs".
 */
final class AdminSystemActions
{
    public function __construct(
        private readonly SystemStatus $system,
        private readonly AuditLog $audit,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        AdminScope::owner($request);

        return JsonResponse::success($response, $this->system->snapshot());
    }

    public function migrate(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $applied = $this->system->migrate();
        if ($applied !== []) {
            $this->audit->record(Actor::user($owner->id), 'admin.migrated', 'system', null, ['applied' => $applied]);
        }

        return JsonResponse::success($response, ['applied' => $applied]);
    }

    public function retryFailed(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $retried = $this->system->retryFailed();
        $this->audit->record(Actor::user($owner->id), 'admin.outbox_retried', 'system', null, ['jobs' => $retried]);

        return JsonResponse::success($response, ['retried' => $retried]);
    }
}
