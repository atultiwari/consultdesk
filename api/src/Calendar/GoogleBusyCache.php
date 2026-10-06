<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Infra\Clock;
use DateTimeImmutable;
use PDO;

/**
 * Google free/busy per provider per bucket (a UTC week). Answers are kept for 2 minutes; an outage is
 * remembered for 1 minute so a down Google is not asked again on every request.
 */
final class GoogleBusyCache
{
    private const TTL_SECONDS = 120;
    private const OUTAGE_TTL_SECONDS = 60;
    private const SQL_DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<Interval>|null busy time ([] during a remembered outage), or null on a miss
     */
    public function get(int $providerId, Interval $bucket): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT busy, fetched_at FROM google_busy_cache WHERE provider_id = :provider AND range_start = :start AND range_end = :end',
        );
        $statement->execute($this->key($providerId, $bucket));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $age = $this->clock->now()->getTimestamp() - (new DateTimeImmutable((string) $row['fetched_at'] . 'Z'))->getTimestamp();
        if ($row['busy'] === null) {
            return $age < self::OUTAGE_TTL_SECONDS ? [] : null;
        }
        if ($age >= self::TTL_SECONDS) {
            return null;
        }

        $intervals = [];
        foreach (json_decode((string) $row['busy'], true) ?: [] as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                $intervals[] = Interval::fromStrings($pair[0], $pair[1]);
            }
        }

        return $intervals;
    }

    /**
     * @param list<Interval>|null $busy null records an outage
     */
    public function put(int $providerId, Interval $bucket, ?array $busy): void
    {
        $this->pdo->prepare(
            'INSERT INTO google_busy_cache (provider_id, range_start, range_end, busy, fetched_at)
             VALUES (:provider, :start, :end, :busy, :fetched)
             ON DUPLICATE KEY UPDATE busy = VALUES(busy), fetched_at = VALUES(fetched_at)',
        )->execute([
            ...$this->key($providerId, $bucket),
            'busy' => $busy === null ? null : json_encode(array_map(static fn(Interval $i): array => [$i->start->format(DATE_ATOM), $i->end->format(DATE_ATOM)], $busy), JSON_THROW_ON_ERROR),
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
    private function key(int $providerId, Interval $bucket): array
    {
        return [
            'provider' => $providerId,
            'start' => $bucket->start->format(self::SQL_DATETIME),
            'end' => $bucket->end->format(self::SQL_DATETIME),
        ];
    }
}
