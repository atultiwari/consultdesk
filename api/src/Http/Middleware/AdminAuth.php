<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Middleware;

use Closure;
use ConsultDesk\Admin\Sessions;
use ConsultDesk\Http\AdminCookie;
use ConsultDesk\Http\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Requires a live admin session, and the CSRF token header on anything but a read.
 * The session is available to actions as the "admin" request attribute.
 */
final class AdminAuth implements MiddlewareInterface
{
    public const ATTRIBUTE = 'admin';
    private const CSRF_HEADER = 'X-CSRF-Token';

    /**
     * @param Closure(): Sessions $sessions
     */
    public function __construct(
        private readonly Closure $sessions,
        private readonly AdminCookie $cookie,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = ($this->sessions)()->resume($this->cookie->read($request));
        if ($session === null) {
            throw new ApiException(401, 'unauthenticated', 'Please sign in again.');
        }

        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) && !hash_equals($session->csrfToken, $request->getHeaderLine(self::CSRF_HEADER))) {
            throw new ApiException(403, 'csrf_failed', 'This page is out of date. Reload it and try again.');
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $session));
    }
}
