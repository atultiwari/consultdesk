<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\BookingRefPrefix;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The booking-code prefix (VRL in VRL-7F3K), for owners and admins.
 */
final class AdminBookingCodeActions
{
    public function __construct(
        private readonly BookingRefPrefix $prefix,
        private readonly AuditLog $audit,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        self::staff($request);

        return JsonResponse::success($response, $this->state());
    }

    public function save(Request $request, Response $response): Response
    {
        $user = self::staff($request);
        $input = new Input(JsonInput::decode($request));
        $raw = $input->string('prefix', required: false, max: 10);
        $prefix = $raw === null ? null : BookingRefPrefix::normalise($raw);
        if ($raw !== null && $prefix === null) {
            $input->reject('prefix', 'Use 2–6 letters or digits, starting with a letter, e.g. VRL.');
        }
        $input->assertValid();

        $before = $this->prefix->current();
        $this->prefix->save($prefix);
        if ($before !== $this->prefix->current()) {
            $this->audit->record(Actor::user($user->id), 'admin.booking_prefix_changed', 'settings', null, ['from' => $before, 'to' => $this->prefix->current()]);
        }

        return JsonResponse::success($response, $this->state());
    }

    /**
     * @return array{prefix: string, default: string}
     */
    private function state(): array
    {
        return ['prefix' => $this->prefix->current(), 'default' => $this->prefix->default()];
    }

    private static function staff(Request $request): \ConsultDesk\Admin\AdminUser
    {
        $user = AdminScope::user($request);

        return $user->isStaff() ? $user : throw ApiException::forbidden();
    }
}
