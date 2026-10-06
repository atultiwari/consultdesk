<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Domain\Booking\BookingView;
use ConsultDesk\Domain\Booking\BookingViewRepository;
use ConsultDesk\Domain\Booking\Customer;
use ConsultDesk\Domain\Booking\HoldRequest;
use ConsultDesk\Domain\Booking\InvalidUtr;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Domain\Booking\PaymentMethodNotAllowed;
use ConsultDesk\Domain\Catalog\CatalogRepository;
use ConsultDesk\Domain\Catalog\ProviderProfile;
use ConsultDesk\Domain\Catalog\ServiceOffering;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\BookingPresenter;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Http\Validation\ValidationFailed;
use ConsultDesk\Infra\Clock;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 * Public booking endpoints: create a booking, read its status, submit a UPI reference.
 */
final class BookingActions
{
    private const HONEYPOT_FIELD = 'website';

    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly BookingService $bookings,
        private readonly BookingViewRepository $views,
        private readonly Clock $clock,
        private readonly string $appUrl,
    ) {}

    public function create(Request $request, Response $response): Response
    {
        $input = JsonInput::from($request);
        if ($input->string(self::HONEYPOT_FIELD, required: false, max: 2000) !== null) {
            throw ApiException::badRequest();
        }

        [$provider, $service] = $this->resolve($input);
        $start = $input->dateTime('start');
        $method = $input->oneOf('payment_method', array_map(static fn(PaymentMethod $m): string => $m->value, PaymentMethod::cases()), required: false);
        $customer = $this->customer($input->nested('customer'));
        $answers = $service->questions->validate($input->map('answers'));
        foreach ($answers->errors as $question => $message) {
            $input->reject("answers.{$question}", $message);
        }
        $input->assertValid();

        $paymentMethod = $this->paymentMethod($service, $provider, $method);
        $held = $this->bookings->hold(new HoldRequest(
            $provider->id,
            $service->id,
            $start ?? throw new RuntimeException('Start was validated as required.'),
            $customer ?? throw new RuntimeException('Customer was validated as required.'),
            $paymentMethod,
            $answers->answers,
        ));
        if ($paymentMethod === PaymentMethod::Free && !$service->requiresApproval) {
            $this->bookings->confirm($held->id, Actor::system());
        }

        return JsonResponse::success($response, [
            'ref' => $held->ref,
            'token' => $held->publicToken,
            'status_url' => sprintf('%s/b/%s?t=%s', $this->appUrl, rawurlencode($held->ref), rawurlencode($held->publicToken)),
            'booking' => BookingPresenter::present($this->view($held->ref), $this->clock->now()),
        ], status: 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $token = $request->getQueryParams()['t'] ?? '';
        $booking = $this->authorised($args['ref'] ?? '', is_string($token) ? $token : '');

        return JsonResponse::success($response, BookingPresenter::present($booking, $this->clock->now()));
    }

    /**
     * @param array<string, string> $args
     */
    public function submitUtr(Request $request, Response $response, array $args): Response
    {
        $input = JsonInput::from($request);
        $token = $input->string('token', max: 64);
        $utr = $input->string('utr', max: 32);
        $input->assertValid();

        $booking = $this->authorised($args['ref'] ?? '', (string) $token);
        try {
            $this->bookings->submitUtr($booking->id, (string) $utr);
        } catch (InvalidUtr $e) {
            throw new ValidationFailed(['utr' => $e->getMessage()]);
        }

        return JsonResponse::success($response, BookingPresenter::present($this->view($booking->ref), $this->clock->now()));
    }

    /**
     * @return array{ProviderProfile, ServiceOffering}
     */
    private function resolve(Input $input): array
    {
        $providerSlug = $input->string('provider', max: 64);
        $serviceSlug = $input->string('service', max: 64);
        if ($providerSlug === null || $serviceSlug === null) {
            $input->assertValid();
        }

        $provider = $this->catalog->activeProvider((string) $providerSlug) ?? throw ApiException::notFound('This provider is not available.');
        $service = $this->catalog->activeService($provider->id, (string) $serviceSlug) ?? throw ApiException::notFound('This service is not available.');

        return [$provider, $service];
    }

    private function customer(Input $input): ?Customer
    {
        $name = $input->string('name', max: 120);
        $email = $input->email('email');
        $phone = $input->phone('phone');
        $timezone = $input->timezone('timezone', required: false);

        return $name === null || $email === null || $phone === null ? null : new Customer($name, $email, $phone, $timezone);
    }

    private function paymentMethod(ServiceOffering $service, ProviderProfile $provider, ?string $requested): PaymentMethod
    {
        $available = $service->availablePaymentMethods($provider);
        $method = $requested === null ? ($available[0] ?? null) : PaymentMethod::from($requested);
        if ($method === null || !in_array($method, $available, true)) {
            throw new PaymentMethodNotAllowed();
        }

        return $method;
    }

    /**
     * Unknown ref, missing token and wrong token all look the same, so refs cannot be probed.
     */
    private function authorised(string $ref, string $token): BookingView
    {
        $booking = $this->views->findByRef($ref);
        if ($booking === null || !$booking->tokenMatches($token)) {
            throw ApiException::notFound('Booking not found. Check the link in your email.');
        }

        return $booking;
    }

    private function view(string $ref): BookingView
    {
        return $this->views->findByRef($ref) ?? throw new RuntimeException("Booking {$ref} vanished.");
    }
}
