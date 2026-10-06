<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\AdminBookings;
use ConsultDesk\Admin\BookingFilter;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\Clock;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Dashboard, booking list and detail, and the confirm/reject/cancel/complete/no-show buttons.
 * Status changes go through BookingService, which audits them and queues notifications.
 */
final class AdminBookingActions
{
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AdminBookings $bookings,
        private readonly BookingService $service,
        private readonly Clock $clock,
    ) {}

    public function dashboard(Request $request, Response $response): Response
    {
        $query = new Input($request->getQueryParams());
        $timezone = $query->timezone('tz', required: false) ?? 'UTC';
        $query->assertValid();

        return JsonResponse::success($response, $this->bookings->dashboard(
            AdminAuthActions::session($request)->user->providerScope(),
            $this->clock->now(),
            new DateTimeZone($timezone),
        ));
    }

    public function list(Request $request, Response $response): Response
    {
        $query = new Input($request->getQueryParams());
        $status = $query->oneOf('status', array_map(static fn(BookingStatus $s): string => $s->value, BookingStatus::cases()), required: false);
        $filter = new BookingFilter(
            providerScope: AdminAuthActions::session($request)->user->providerScope(),
            providerId: $query->int('provider', required: false, min: 1),
            status: $status === null ? null : BookingStatus::from($status),
            search: $query->string('q', required: false, max: 100),
            from: $query->dateTime('from', required: false),
            to: $query->dateTime('to', required: false),
            page: $query->int('page', required: false, min: 1, max: 10_000) ?? 1,
            perPage: $query->int('per_page', required: false, min: 1, max: self::MAX_PER_PAGE) ?? 25,
        );
        $query->assertValid();

        [$rows, $total] = $this->bookings->search($filter);

        return JsonResponse::success($response, $rows, ['total' => $total, 'page' => $filter->page, 'per_page' => $filter->perPage]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        return JsonResponse::success($response, $this->detail($request, (int) ($args['id'] ?? 0)));
    }

    /**
     * @param array<string, string> $args
     */
    public function act(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $this->detail($request, $id);
        $actor = Actor::user(AdminAuthActions::session($request)->user->id);

        match ($args['action'] ?? '') {
            'confirm' => $this->service->confirm($id, $actor),
            'reject' => $this->service->reject($id, $actor),
            'cancel' => $this->service->cancel($id, $actor),
            'complete' => $this->service->complete($id, $actor),
            'no-show' => $this->service->markNoShow($id, $actor),
            default => throw ApiException::notFound(),
        };

        return JsonResponse::success($response, $this->detail($request, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Request $request, int $id): array
    {
        return $this->bookings->find($id, AdminAuthActions::session($request)->user->providerScope(), $this->clock->now())
            ?? throw ApiException::notFound();
    }
}
