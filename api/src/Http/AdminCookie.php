<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin session cookie: httpOnly, SameSite=Strict, and on https also Secure with the __Host-
 * prefix (which pins it to this exact host and path).
 */
final class AdminCookie
{
    private const MAX_AGE = 30 * 24 * 3600;

    public function __construct(private readonly bool $secure) {}

    public function name(): string
    {
        return $this->secure ? '__Host-cd_admin' : 'cd_admin';
    }

    public function read(ServerRequestInterface $request): string
    {
        foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($key === $this->name()) {
                return preg_match('/^[A-Za-z0-9_-]{43}$/', $value) === 1 ? $value : '';
            }
        }

        return '';
    }

    public function set(ResponseInterface $response, #[\SensitiveParameter] string $token): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->header($token, self::MAX_AGE));
    }

    public function clear(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->header('', 0));
    }

    private function header(string $value, int $maxAge): string
    {
        return sprintf('%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Strict%s', $this->name(), $value, $maxAge, $this->secure ? '; Secure' : '');
    }
}
