<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\SiteSetup;
use ConsultDesk\Domain\Availability\SlotFinder;
use ConsultDesk\Domain\Availability\SlotRequest;
use ConsultDesk\Domain\Catalog\CatalogRepository;
use ConsultDesk\Domain\Catalog\ProviderProfile;
use ConsultDesk\Domain\Catalog\ServiceOffering;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\BookingPresenter;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Http\Validation\ValidationFailed;
use ConsultDesk\Infra\Clock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Read-only public catalog: providers, their services and open slots.
 */
final class ProviderActions
{
    private const DEFAULT_RANGE_DAYS = 14;

    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly SlotFinder $slots,
        private readonly Clock $clock,
        private readonly string $appUrl,
        private readonly ?SiteSetup $setup = null,
    ) {}

    public function site(Request $request, Response $response): Response
    {
        $providers = $this->catalog->activeProviders();
        $single = $this->setup?->isSingle() === true;

        return JsonResponse::success($response, [
            ...$this->catalog->siteSettings()->toArray(),
            'mode' => $this->setup?->stored()['mode'],
            // A one-teacher site (or a site with only one teacher so far) goes straight to their page.
            'single_provider' => ($single || count($providers) === 1) && $providers !== [] ? $providers[0]->slug : null,
        ]);
    }

    public function list(Request $request, Response $response): Response
    {
        return JsonResponse::success($response, array_map(
            fn(ProviderProfile $p): array => $p->toPublicArray($this->appUrl),
            $this->catalog->activeProviders(),
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $provider = $this->provider($args['provider'] ?? '');

        return JsonResponse::success($response, [
            'provider' => $provider->toPublicArray($this->appUrl),
            'services' => array_map(
                static fn(ServiceOffering $s): array => $s->toPublicArray($provider),
                $this->catalog->activeServices($provider->id),
            ),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function slots(Request $request, Response $response, array $args): Response
    {
        $provider = $this->provider($args['provider'] ?? '');
        $service = $this->catalog->activeService($provider->id, $args['service'] ?? '') ?? throw ApiException::notFound();

        $query = new Input($request->getQueryParams());
        $timezone = new DateTimeZone($provider->timezone);
        $from = $query->date('from', required: false) ?? $this->clock->now()->setTimezone($timezone)->format('Y-m-d');
        $to = $query->date('to', required: false)
            ?? (new DateTimeImmutable($from))->modify(sprintf('+%d days', self::DEFAULT_RANGE_DAYS - 1))->format('Y-m-d');
        $query->assertValid();
        if ($to < $from) {
            throw new ValidationFailed(['to' => 'Must be on or after the start date.']);
        }

        try {
            $slots = $this->slots->find($provider->id, $service->id, $service->durationMinutes, $from, $to);
        } catch (InvalidArgumentException) {
            throw new ValidationFailed(['to' => sprintf('Ask for at most %d days at a time.', SlotRequest::MAX_RANGE_DAYS)]);
        }

        return JsonResponse::success($response, [
            'timezone' => $provider->timezone,
            'from' => $from,
            'to' => $to,
            'slots' => array_map(static fn($s): array => [
                'start' => $s->start->format(BookingPresenter::ISO),
                'end' => $s->end->format(BookingPresenter::ISO),
            ], $slots),
        ]);
    }

    private function provider(string $slug): ProviderProfile
    {
        return $this->catalog->activeProvider($slug) ?? throw ApiException::notFound();
    }
}
