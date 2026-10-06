<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Writes the standard API envelope: {success, data, error, meta}.
 */
final class JsonResponse
{
    /**
     * @param array<string, mixed>|null $meta
     */
    public static function success(ResponseInterface $response, mixed $data, ?array $meta = null, int $status = 200): ResponseInterface
    {
        return self::write($response, ['success' => true, 'data' => $data, 'error' => null, 'meta' => $meta], $status);
    }

    public static function error(ResponseInterface $response, string $code, string $message, int $status): ResponseInterface
    {
        return self::write(
            $response,
            ['success' => false, 'data' => null, 'error' => ['code' => $code, 'message' => $message], 'meta' => null],
            $status,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function write(ResponseInterface $response, array $body, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
