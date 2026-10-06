<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use InvalidArgumentException;

final class PayloadReader
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Job payload is missing integer "%s".', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Job payload is missing string "%s".', $key));
        }

        return $value;
    }
}
