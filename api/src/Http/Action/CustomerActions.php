<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Customer\CustomerAccess;
use ConsultDesk\Customer\CustomerBookings;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\CustomerCookie;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * "My bookings": ask for a sign-in link, sign in with it, list bookings, cancel an unpaid one.
 * Writes need the session cookie (SameSite=Strict) and a JSON body, which other sites can't send
 * without the browser asking first.
 */
final class CustomerActions
{
    public function __construct(
        private readonly CustomerAccess $access,
        private readonly CustomerBookings $bookings,
        private readonly BookingService $service,
        private readonly CustomerCookie $cookie,
        private readonly Clock $clock,
        private readonly RateLimiter $limiter,
    ) {}

    private const LINK_REQUESTS_PER_DAY = 10;

    /**
     * Always the same answer, so it can't be used to find out who has booked.
     */
    public function requestLink(Request $request, Response $response): Response
    {
        $input = JsonInput::from($request);
        $email = $input->email('email');
        $input->assertValid();

        // Per address as well as per IP: nobody can flood someone's inbox (or our queue) with links.
        if ($this->limiter->hit('my-link-email', strtolower((string) $email), self::LINK_REQUESTS_PER_DAY, 86400) === null) {
            $this->access->requestLink((string) $email);
        }

        return JsonResponse::success($response, ['ok' => true]);
    }

    public function signIn(Request $request, Response $response): Response
    {
        $input = JsonInput::from($request);
        $token = $input->string('token', max: 64);
        $input->assertValid();

        $session = $this->access->signIn((string) $token)
            ?? throw ApiException::notFound('This link has expired or was already used. Ask for a new one.');

        return $this->cookie->set(JsonResponse::success($response, ['email' => $session['email']]), $session['token']);
    }

    public function list(Request $request, Response $response): Response
    {
        $email = $this->signedIn($request);

        return JsonResponse::success($response, ['email' => $email, ...$this->bookings->for($email, $this->clock->now())])
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, string> $args
     */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $email = $this->signedIn($request);
        JsonInput::from($request); // a JSON body only: plain cross-site forms can't send one
        $booking = $this->bookings->find($email, (string) ($args['ref'] ?? '')) ?? throw ApiException::notFound('Booking not found.');
        if (!$this->service->cancelUnpaid($booking->id, Actor::customer())) {
            throw new ApiException(409, 'contact_teacher', sprintf('This booking can’t be cancelled here. Please contact %s.', $booking->providerName));
        }

        return JsonResponse::success($response, ['email' => $email, ...$this->bookings->for($email, $this->clock->now())]);
    }

    public function signOut(Request $request, Response $response): Response
    {
        $this->access->signOut($this->cookie->read($request));

        return $this->cookie->clear(JsonResponse::success($response, ['ok' => true]));
    }

    private function signedIn(Request $request): string
    {
        return $this->access->emailFor($this->cookie->read($request))
            ?? throw new ApiException(401, 'unauthenticated', 'Please sign in with the link we email you.');
    }
}
