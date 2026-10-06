<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Domain\Booking;

use ConsultDesk\Tests\Integration\IntegrationTestCase;
use ConsultDesk\Tests\Integration\Support\Fixtures;
use RuntimeException;

/**
 * Proves hold() is safe under real concurrency: separate PHP processes with their own
 * connections race for the same slot, and exactly one may win.
 */
final class ConcurrentHoldTest extends IntegrationTestCase
{
    private const WORKERS = 8;
    private const WORKER = __DIR__ . '/../../Support/hold_worker.php';

    public function testOnlyOneOfManySimultaneousHoldsForTheSameSlotSucceeds(): void
    {
        $providerId = Fixtures::provider($this->pdo, ['max_per_day' => null]);
        $serviceId = Fixtures::service($this->pdo, $providerId);

        $results = $this->race($providerId, $serviceId, array_fill(0, self::WORKERS, '2026-10-07T04:30:00Z'));

        $this->assertExactlyOneWinner($results);
        self::assertSame(1, $this->activeBookings($providerId));
    }

    public function testOnlyOneOfOverlappingSlotsWithinTheGapSucceeds(): void
    {
        // 60-minute sessions with a 10-minute gap: every start below conflicts with every other.
        $providerId = Fixtures::provider($this->pdo, ['max_per_day' => null]);
        $serviceId = Fixtures::service($this->pdo, $providerId);
        $starts = ['04:30', '04:45', '05:00', '04:00', '04:15', '04:35', '04:50', '05:05'];

        $results = $this->race($providerId, $serviceId, array_map(static fn(string $t): string => "2026-10-07T{$t}:00Z", $starts));

        $this->assertExactlyOneWinner($results);
        self::assertSame(1, $this->activeBookings($providerId));
    }

    /**
     * @param list<string> $starts
     *
     * @return list<string>
     */
    private function race(int $providerId, int $serviceId, array $starts): array
    {
        $goAt = sprintf('%.6F', microtime(true) + 1.0);
        $processes = [];
        foreach ($starts as $start) {
            $command = [PHP_BINARY, self::WORKER, (string) $providerId, (string) $serviceId, $start, $goAt];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start a hold worker.');
            }
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = trim((string) stream_get_contents($pipes[1]));
            $errors = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $results[] = $errors === '' ? $output : "{$output} [stderr: {$errors}]";
        }

        return $results;
    }

    /**
     * @param list<string> $results
     */
    private function assertExactlyOneWinner(array $results): void
    {
        $winners = array_filter($results, static fn(string $r): bool => str_starts_with($r, 'ok '));
        $losers = array_filter($results, static fn(string $r): bool => str_starts_with($r, 'SlotUnavailable'));

        self::assertCount(1, $winners, 'Results: ' . implode(' | ', $results));
        self::assertCount(count($results) - 1, $losers, 'Every other worker should get SlotUnavailable: ' . implode(' | ', $results));
    }

    private function activeBookings(int $providerId): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM bookings WHERE provider_id = ? AND status = 'held'");
        $statement->execute([$providerId]);

        return (int) $statement->fetchColumn();
    }
}
