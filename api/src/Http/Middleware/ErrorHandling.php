<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Middleware;

use ConsultDesk\Domain\DomainError;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\ValidationFailed;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Turns every failure into the JSON envelope. Expected errors keep their safe message; anything
 * unexpected is logged in full and answered with a generic 500 (details only in debug mode).
 */
final class ErrorHandling implements MiddlewareInterface
{
    /** Domain error code => HTTP status. Unlisted domain errors are 422. */
    private const STATUS = [
        'slot_unavailable' => 409,
        'daily_limit_reached' => 409,
        'duplicate_utr' => 409,
        'invalid_transition' => 409,
        'session_not_started' => 409,
        'booking_not_found' => 404,
        'service_not_bookable' => 404,
        'hold_expired' => 410,
        'too_many_open_bookings' => 409,
    ];

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly bool $debug,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationFailed $e) {
            return JsonResponse::error($this->responses->createResponse(), $e->errorCode(), $e->getMessage(), 422, $e->fields);
        } catch (DomainError $e) {
            return JsonResponse::error($this->responses->createResponse(), $e->errorCode(), $e->getMessage(), self::STATUS[$e->errorCode()] ?? 422);
        } catch (ApiException $e) {
            $response = JsonResponse::error($this->responses->createResponse(), $e->errorCode, $e->getMessage(), $e->status);
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        } catch (HttpNotFoundException) {
            return JsonResponse::error($this->responses->createResponse(), 'not_found', 'Not found.', 404);
        } catch (HttpMethodNotAllowedException $e) {
            return JsonResponse::error($this->responses->createResponse(), 'method_not_allowed', 'Method not allowed.', 405)
                ->withHeader('Allow', implode(', ', $e->getAllowedMethods()));
        } catch (HttpException $e) {
            return JsonResponse::error($this->responses->createResponse(), 'http_error', $e->getTitle(), $e->getCode());
        } catch (Throwable $e) {
            error_log(sprintf('[consultdesk] %s %s: %s', $request->getMethod(), $request->getUri()->getPath(), (string) $e));
            $message = $this->debug ? $e->getMessage() : 'Something went wrong. Please try again.';

            return JsonResponse::error($this->responses->createResponse(), 'server_error', $message, 500);
        }
    }
}
