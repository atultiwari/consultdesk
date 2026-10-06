<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use InvalidArgumentException;

final class DbConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $name,
        public readonly string $user,
        #[\SensitiveParameter]
        public readonly string $password,
    ) {
        if ($host === '' || $name === '' || $user === '') {
            throw new InvalidArgumentException('Database host, name and user are required.');
        }
    }

    /**
     * Reads DB_HOST, DB_PORT, DB_USER, DB_PASSWORD and the database name from $nameVariable.
     *
     * @param array<string, string> $env
     */
    public static function fromEnv(array $env, string $nameVariable = 'DB_NAME'): self
    {
        return new self(
            host: $env['DB_HOST'] ?? '',
            port: (int) ($env['DB_PORT'] ?? 3306),
            name: $env[$nameVariable] ?? '',
            user: $env['DB_USER'] ?? '',
            password: $env['DB_PASSWORD'] ?? '',
        );
    }

    public function dsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->name);
    }
}
