<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Headers for JSON API responses. no-referrer matters: status-page URLs carry a secret token.
 */
final class SecurityHeaders implements MiddlewareInterface
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store',
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        foreach (self::HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
