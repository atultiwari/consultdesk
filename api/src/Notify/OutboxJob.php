<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

final class OutboxJob
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly array $payload,
        public readonly int $attempts,
    ) {
    }
}
