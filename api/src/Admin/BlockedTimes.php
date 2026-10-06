<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Closures: time off for one provider, or for everyone (provider_id NULL).
 */
final class BlockedTimes
{
    private const SQL = 'Y-m-d H:i:s';
    private const ISO = 'Y-m-d\TH:i:s\Z';
    private const SELECT = 'SELECT bp.*, p.name AS provider_name FROM blocked_periods bp LEFT JOIN providers p ON p.id = bp.provider_id';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Current and future closures the viewer can see: their own and organisation-wide ones.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(?int $providerScope, DateTimeImmutable $now): array
    {
        $scope = $providerScope === null ? '' : ' AND (bp.provider_id = :scope OR bp.provider_id IS NULL)';
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE bp.end_at > :now' . $scope . ' ORDER BY bp.start_at, bp.id');
        $statement->execute(['now' => $now->format(self::SQL), ...($providerScope === null ? [] : ['scope' => $providerScope])]);

        return array_values(array_map(self::present(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE bp.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::present($row) : null;
    }

    public function create(?int $providerId, DateTimeImmutable $start, DateTimeImmutable $end, bool $allDay, ?string $reason): int
    {
        $this->pdo->prepare(
            'INSERT INTO blocked_periods (provider_id, start_at, end_at, all_day, reason)
             VALUES (:provider_id, :start_at, :end_at, :all_day, :reason)',
        )->execute([
            'provider_id' => $providerId,
            'start_at' => $start->format(self::SQL),
            'end_at' => $end->format(self::SQL),
            'all_day' => (int) $allDay,
            'reason' => $reason,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM blocked_periods WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function present(array $r): array
    {
        $utc = new DateTimeZone('UTC');

        return [
            'id' => (int) $r['id'],
            'provider_id' => $r['provider_id'] === null ? null : (int) $r['provider_id'],
            'provider_name' => $r['provider_name'],
            'start' => (new DateTimeImmutable((string) $r['start_at'], $utc))->format(self::ISO),
            'end' => (new DateTimeImmutable((string) $r['end_at'], $utc))->format(self::ISO),
            'all_day' => (bool) $r['all_day'],
            'reason' => $r['reason'],
        ];
    }
}
