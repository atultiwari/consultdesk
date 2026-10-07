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
     * A provider with a 5-minute slot interval. Unless $openAllWeek is false it is available
     * 00:00–23:55 local time every day, so tests can book almost any aligned time.
     *
     * @param array<string, scalar|null> $overrides
     */
    public static function provider(PDO $pdo, array $overrides = [], bool $openAllWeek = true): int
    {
        static $n = 0;
        $n++;

        $id = self::insert($pdo, 'providers', array_merge([
            'slug' => "provider-{$n}",
            'name' => "Test Provider {$n}",
            'timezone' => 'Asia/Kolkata',
            'upi_vpa' => 'placeholder@upi',
            'upi_payee_name' => 'Placeholder Payee',
            'slot_interval' => 5,
        ], $overrides));

        if ($openAllWeek) {
            foreach (range(1, 7) as $weekday) {
                self::availability($pdo, $id, $weekday, '00:00', '23:55');
            }
        }

        return $id;
    }

    public static function availability(PDO $pdo, int $providerId, int $weekday, string $start, string $end, ?int $serviceId = null): int
    {
        return self::insert($pdo, 'availability_rules', [
            'provider_id' => $providerId,
            'service_id' => $serviceId,
            'weekday' => $weekday,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    public static function blocked(PDO $pdo, ?int $providerId, string $startUtc, string $endUtc): int
    {
        return self::insert($pdo, 'blocked_periods', [
            'provider_id' => $providerId,
            'start_at' => $startUtc,
            'end_at' => $endUtc,
            'reason' => 'Test closure',
        ]);
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

    /**
     * @param array<string, scalar|null> $overrides
     */
    public static function coupon(PDO $pdo, string $code, array $overrides = []): int
    {
        return self::insert($pdo, 'coupons', array_merge([
            'code' => $code,
            'kind' => 'percent',
            'value' => 20,
            'once_per_email' => 1,
            'active' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides));
    }

    public static function user(PDO $pdo, string $role = 'admin', ?int $providerId = null, ?string $email = null): int
    {
        static $n = 0;
        $n++;

        return self::insert($pdo, 'users', [
            'email' => $email ?? "user{$n}@example.test",
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
