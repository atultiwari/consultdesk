<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Middleware;

use Closure;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\ClientIp;
use ConsultDesk\Infra\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RateLimit implements MiddlewareInterface
{
    /**
     * @param Closure(): RateLimiter $limiter built on first request, so routing needs no database
     */
    public function __construct(
        private readonly Closure $limiter,
        private readonly string $bucket,
        private readonly int $limit,
        private readonly int $windowSeconds,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $retryAfter = ($this->limiter)()->hit($this->bucket, ClientIp::of($request), $this->limit, $this->windowSeconds);
        if ($retryAfter !== null) {
            throw new ApiException(429, 'rate_limited', 'Too many requests. Please wait a little and try again.', ['Retry-After' => (string) $retryAfter]);
        }

        return $handler->handle($request);
    }
}
