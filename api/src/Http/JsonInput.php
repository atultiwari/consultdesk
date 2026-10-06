<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use ConsultDesk\Http\Validation\Input;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a JSON request body. Requiring application/json also means a cross-site form post cannot
 * reach these endpoints without a CORS preflight, which the API never grants.
 */
final class JsonInput
{
    private const MAX_BYTES = 64 * 1024;

    public static function from(ServerRequestInterface $request): Input
    {
        if (!str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            throw new ApiException(415, 'unsupported_media_type', 'Send the request body as application/json.');
        }

        if ((int) $request->getHeaderLine('Content-Length') > self::MAX_BYTES) {
            throw new ApiException(413, 'payload_too_large', 'The request body is too large.');
        }
        $raw = $request->getBody()->read(self::MAX_BYTES + 1);
        if (strlen($raw) > self::MAX_BYTES) {
            throw new ApiException(413, 'payload_too_large', 'The request body is too large.');
        }

        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ApiException::badRequest('The request body is not valid JSON.');
        }
        if (!is_array($data)) {
            throw ApiException::badRequest('The request body must be a JSON object.');
        }

        return new Input($data);
    }
}
