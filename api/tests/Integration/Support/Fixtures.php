<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Support;

use PDO;

/**
 * Inserts rows with sensible defaults. All contact and payment details are obvious placeholders.
 */
final class Fixtures
{
    /**
     * @param array<string, scalar|null> $overrides
     */
    public static function provider(PDO $pdo, array $overrides = []): int
    {
        static $n = 0;
        $n++;

        return self::insert($pdo, 'providers', array_merge([
            'slug' => "provider-{$n}",
            'name' => "Test Provider {$n}",
            'timezone' => 'Asia/Kolkata',
            'upi_vpa' => 'placeholder@upi',
            'upi_payee_name' => 'Placeholder Payee',
        ], $overrides));
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    public static function service(PDO $pdo, int $providerId, array $overrides = []): int
    {
        static $n = 0;
        $n++;

        return self::insert($pdo, 'services', array_merge([
            'provider_id' => $providerId,
            'slug' => "service-{$n}",
            'title' => "Test Service {$n}",
            'duration_min' => 60,
            'price_minor' => 149900,
            'currency' => 'INR',
            'requires_approval' => 0,
            'payment_methods' => json_encode(['upi', 'razorpay_link'], JSON_THROW_ON_ERROR),
            'questions' => '[]',
        ], $overrides));
    }

    public static function user(PDO $pdo, string $role = 'admin', ?int $providerId = null): int
    {
        static $n = 0;
        $n++;

        return self::insert($pdo, 'users', [
            'email' => "user{$n}@example.test",
            'password_hash' => 'not-a-real-hash',
            'role' => $role,
            'provider_id' => $providerId,
        ]);
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private static function insert(PDO $pdo, string $table, array $row): int
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
        );
        $pdo->prepare($sql)->execute($row);

        return (int) $pdo->lastInsertId();
    }
}
