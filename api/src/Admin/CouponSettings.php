<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Domain\Coupon\Coupons;
use ConsultDesk\Infra\Clock;
use PDO;

/**
 * Coupons in the admin panel: staff see and manage every coupon; a teacher manages their own and
 * sees the site-wide ones (read-only). Each comes with how many bookings are using it.
 */
final class CouponSettings
{
    private const SQL = 'Y-m-d H:i:s';
    public const COLUMNS = ['code', 'provider_id', 'kind', 'value', 'service_ids', 'valid_from', 'valid_until', 'max_uses', 'once_per_email', 'active', 'note'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(AdminUser $viewer): array
    {
        $scope = $viewer->providerScope();
        $statement = $this->pdo->prepare(
            'SELECT c.*, p.name AS provider_name, (' . self::usesSql() . ') AS uses FROM coupons c
             LEFT JOIN providers p ON p.id = c.provider_id'
            . ($scope === null ? '' : ' WHERE c.provider_id = :scope OR c.provider_id IS NULL')
            . ' ORDER BY c.active DESC, c.created_at DESC, c.id DESC',
        );
        $statement->execute([...$this->usingParams(), ...($scope === null ? [] : ['scope' => $scope])]);

        return array_values(array_map(fn(array $r): array => $this->present($r, $viewer), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.*, p.name AS provider_name, (' . self::usesSql() . ') AS uses FROM coupons c
             LEFT JOIN providers p ON p.id = c.provider_id WHERE c.id = :id',
        );
        $statement->execute([...$this->usingParams(), 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $values columns from COLUMNS
     */
    public function create(array $values, int $createdBy): int
    {
        $values = $this->encode(array_intersect_key($values, array_flip(self::COLUMNS)));
        $now = $this->clock->now()->format(self::SQL);
        $columns = [...array_keys($values), 'created_by', 'created_at', 'updated_at'];
        $this->pdo->prepare(sprintf(
            'INSERT INTO coupons (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
        ))->execute([...$values, 'created_by' => $createdBy, 'created_at' => $now, 'updated_at' => $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values columns from COLUMNS
     */
    public function update(int $id, array $values): void
    {
        $values = $this->encode(array_intersect_key($values, array_flip(self::COLUMNS)));
        if ($values === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn(string $c): string => "{$c} = :{$c}", array_keys($values)));
        $this->pdo->prepare("UPDATE coupons SET {$sets}, updated_at = :updated WHERE id = :id")
            ->execute([...$values, 'updated' => $this->clock->now()->format(self::SQL), 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM coupons WHERE id = :id')->execute(['id' => $id]);
    }

    public function codeTaken(string $code, ?int $exceptId = null): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM coupons WHERE code = :code' . ($exceptId === null ? '' : ' AND id <> :id'));
        $statement->execute(['code' => $code, ...($exceptId === null ? [] : ['id' => $exceptId])]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function providerExists(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM providers WHERE id = :id');
        $statement->execute(['id' => $id]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Whether every one of these sessions exists and (for a teacher's coupon) is that teacher's.
     *
     * @param list<int> $serviceIds
     */
    public function servicesBelong(array $serviceIds, ?int $providerId): bool
    {
        if ($serviceIds === []) {
            return true;
        }
        $placeholders = implode(', ', array_map(static fn(int $i): string => ':s' . $i, array_keys($serviceIds)));
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM services WHERE id IN ({$placeholders})" . ($providerId === null ? '' : ' AND provider_id = :provider'),
        );
        $params = [];
        foreach ($serviceIds as $i => $id) {
            $params['s' . $i] = $id;
        }
        $statement->execute([...$params, ...($providerId === null ? [] : ['provider' => $providerId])]);

        return (int) $statement->fetchColumn() === count(array_unique($serviceIds));
    }

    /**
     * Staff can change any coupon; a teacher only their own.
     *
     * @param array<string, mixed> $row
     */
    public static function canEdit(AdminUser $viewer, array $row): bool
    {
        return $viewer->isStaff() || ($row['provider_id'] !== null && (int) $row['provider_id'] === $viewer->providerId);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    public function present(array $r, AdminUser $viewer): array
    {
        $services = is_string($r['service_ids'] ?? null) ? json_decode($r['service_ids'], true) : null;

        return [
            'id' => (int) $r['id'],
            'code' => (string) $r['code'],
            'provider_id' => $r['provider_id'] === null ? null : (int) $r['provider_id'],
            'provider_name' => $r['provider_name'] ?? null,
            'kind' => (string) $r['kind'],
            'value' => (int) $r['value'],
            'service_ids' => is_array($services) ? array_values(array_map('intval', $services)) : null,
            'valid_from' => self::iso($r['valid_from']),
            'valid_until' => self::iso($r['valid_until']),
            'max_uses' => $r['max_uses'] === null ? null : (int) $r['max_uses'],
            'once_per_email' => (bool) $r['once_per_email'],
            'active' => (bool) $r['active'],
            // A teacher sees site-wide coupons, but not their notes or how often other teachers' customers used them.
            'note' => self::canEdit($viewer, $r) ? $r['note'] : null,
            'uses' => self::canEdit($viewer, $r) ? (int) ($r['uses'] ?? 0) : null,
            'editable' => self::canEdit($viewer, $r),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, scalar|null>
     */
    private function encode(array $values): array
    {
        if (array_key_exists('service_ids', $values)) {
            $values['service_ids'] = $values['service_ids'] === null ? null : json_encode(array_values($values['service_ids']), JSON_THROW_ON_ERROR);
        }
        foreach (['once_per_email', 'active'] as $flag) {
            if (array_key_exists($flag, $values)) {
                $values[$flag] = (int) (bool) $values[$flag];
            }
        }

        return $values;
    }

    private static function usesSql(): string
    {
        $placeholders = implode(', ', array_map(static fn(int $i): string => ':u' . $i, array_keys(Coupons::USING)));

        return "SELECT COUNT(*) FROM bookings b WHERE b.coupon_id = c.id AND b.status IN ({$placeholders})
            AND (b.status <> 'held' OR b.hold_expires_at > :u_now)";
    }

    /**
     * @return array<string, string>
     */
    private function usingParams(): array
    {
        $params = ['u_now' => $this->clock->now()->format(self::SQL)];
        foreach (Coupons::USING as $i => $status) {
            $params['u' . $i] = $status;
        }

        return $params;
    }

    private static function iso(mixed $value): ?string
    {
        return is_string($value) ? (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z') : null;
    }
}
