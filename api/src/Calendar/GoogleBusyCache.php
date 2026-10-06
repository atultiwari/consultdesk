<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Infra\Clock;
use PDO;

/**
 * Free/busy answers kept for two minutes, so busy booking pages do not hit Google on every request.
 */
final class GoogleBusyCache
{
    private const TTL_SECONDS = 120;
    private const SQL_DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<Interval>|null null on a miss
     */
    public function get(int $providerId, Interval $range): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT busy FROM google_busy_cache
             WHERE provider_id = :provider AND range_start = :start AND range_end = :end AND fetched_at > :fresh_after',
        );
        $statement->execute([
            ...$this->key($providerId, $range),
            'fresh_after' => $this->clock->now()->modify(sprintf('-%d seconds', self::TTL_SECONDS))->format(self::SQL_DATETIME),
        ]);
        $json = $statement->fetchColumn();
        if (!is_string($json)) {
            return null;
        }

        $intervals = [];
        foreach (json_decode($json, true) ?: [] as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                $intervals[] = Interval::fromStrings($pair[0], $pair[1]);
            }
        }

        return $intervals;
    }

    /**
     * @param list<Interval> $busy
     */
    public function put(int $providerId, Interval $range, array $busy): void
    {
        $this->pdo->prepare(
            'INSERT INTO google_busy_cache (provider_id, range_start, range_end, busy, fetched_at)
             VALUES (:provider, :start, :end, :busy, :fetched)
             ON DUPLICATE KEY UPDATE busy = VALUES(busy), fetched_at = VALUES(fetched_at)',
        )->execute([
            ...$this->key($providerId, $range),
            'busy' => json_encode(array_map(static fn(Interval $i): array => [$i->start->format(DATE_ATOM), $i->end->format(DATE_ATOM)], $busy), JSON_THROW_ON_ERROR),
            'fetched' => $this->clock->now()->format(self::SQL_DATETIME),
        ]);
    }

    public function prune(): int
    {
        $statement = $this->pdo->prepare('DELETE FROM google_busy_cache WHERE fetched_at < :cutoff');
        $statement->execute(['cutoff' => $this->clock->now()->modify('-1 hour')->format(self::SQL_DATETIME)]);

        return $statement->rowCount();
    }

    /**
     * @return array{provider: int, start: string, end: string}
     */
    private function key(int $providerId, Interval $range): array
    {
        return [
            'provider' => $providerId,
            'start' => $range->start->format(self::SQL_DATETIME),
            'end' => $range->end->format(self::SQL_DATETIME),
        ];
    }
}
