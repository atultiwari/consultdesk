<?php

declare(strict_types=1);

namespace ConsultDesk\Http;

use RuntimeException;

/**
 * An HTTP-level failure with a fixed status, error code and safe message.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function badRequest(string $message = 'The request could not be processed.'): self
    {
        return new self(400, 'bad_request', $message);
    }

    public static function forbidden(): self
    {
        return new self(403, 'forbidden', 'Forbidden.');
    }
}
