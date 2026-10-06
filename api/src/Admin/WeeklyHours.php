<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Infra\Db;
use PDO;

/**
 * A provider's weekly availability windows, saved as a whole.
 */
final class WeeklyHours
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Db $db,
    ) {}

    /**
     * @return list<array{id: int, weekday: int, start: string, end: string, service_id: ?int}>
     */
    public function forProvider(int $providerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, weekday, start_time, end_time, service_id FROM availability_rules
             WHERE provider_id = :id ORDER BY weekday, start_time, id',
        );
        $statement->execute(['id' => $providerId]);

        return array_values(array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'weekday' => (int) $r['weekday'],
            'start' => substr((string) $r['start_time'], 0, 5),
            'end' => substr((string) $r['end_time'], 0, 5),
            'service_id' => $r['service_id'] === null ? null : (int) $r['service_id'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @param list<array{weekday: int, start: string, end: string, service_id: ?int}> $rules
     */
    public function replace(int $providerId, array $rules): void
    {
        $this->db->transaction(function () use ($providerId, $rules): void {
            $this->pdo->prepare('DELETE FROM availability_rules WHERE provider_id = :id')->execute(['id' => $providerId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO availability_rules (provider_id, service_id, weekday, start_time, end_time)
                 VALUES (:provider_id, :service_id, :weekday, :start_time, :end_time)',
            );
            foreach ($rules as $rule) {
                $insert->execute([
                    'provider_id' => $providerId,
                    'service_id' => $rule['service_id'],
                    'weekday' => $rule['weekday'],
                    'start_time' => $rule['start'],
                    'end_time' => $rule['end'],
                ]);
            }
        });
    }
}
