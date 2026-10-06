<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Notify;

use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Notify\JobHandler;
use ConsultDesk\Notify\Outbox;
use ConsultDesk\Notify\OutboxWorker;
use ConsultDesk\Tests\Integration\IntegrationTestCase;
use RuntimeException;

final class OutboxTest extends IntegrationTestCase
{
    public function testEnqueuedJobsAreClaimedInOrderOnceDue(): void
    {
        $outbox = $this->outbox('2026-10-05T00:00Z');
        $outbox->enqueue('a', ['n' => 1]);
        $outbox->enqueue('b', ['n' => 2]);
        $outbox->enqueue('later', [], availableAt: new \DateTimeImmutable('2026-10-05T00:05Z'));

        $claimed = $outbox->claimDue(10);

        self::assertSame(['a', 'b'], array_map(static fn($j) => $j->type, $claimed));
        self::assertSame(['n' => 1], $claimed[0]->payload);
        self::assertSame(1, $claimed[0]->attempts);
        self::assertSame([], $outbox->claimDue(10), 'running jobs are not claimed twice');
        self::assertSame(['later'], array_map(static fn($j) => $j->type, $this->outbox('2026-10-05T00:05Z')->claimDue(10)));
    }

    public function testDedupeKeyKeepsOnlyTheFirstJob(): void
    {
        $outbox = $this->outbox('2026-10-05T00:00Z');
        $outbox->enqueue('email', ['v' => 1], 'booking.held:1');
        $outbox->enqueue('email', ['v' => 2], 'booking.held:1');

        $claimed = $outbox->claimDue(10);
        self::assertCount(1, $claimed);
        self::assertSame(['v' => 1], $claimed[0]->payload);
    }

    public function testFailuresBackOffExponentiallyThenGiveUp(): void
    {
        $outbox = $this->outbox('2026-10-05T00:00Z');
        $outbox->enqueue('flaky', []);
        $job = $outbox->claimDue(1)[0];

        $outbox->fail($job, 'SMTP timeout');
        self::assertSame([], $this->outbox('2026-10-05T00:01:59Z')->claimDue(1), 'first retry waits 2 minutes');
        $retry = $this->outbox('2026-10-05T00:02Z')->claimDue(1);
        self::assertCount(1, $retry);
        self::assertSame(2, $retry[0]->attempts);

        $row = $this->row($job->id);
        self::assertSame('running', $row['status']);

        $last = $retry[0];
        for ($i = $last->attempts; $i < Outbox::MAX_ATTEMPTS; $i++) {
            $this->outbox('2026-10-05T00:02Z')->fail($last, 'still down');
            $this->pdo->exec("UPDATE outbox_jobs SET available_at = '2026-10-05 00:00:00' WHERE id = {$job->id}");
            $last = $this->outbox('2026-10-05T00:02Z')->claimDue(1)[0];
        }
        $this->outbox('2026-10-05T00:02Z')->fail($last, 'gave up');

        $row = $this->row($job->id);
        self::assertSame('failed', $row['status']);
        self::assertSame(Outbox::MAX_ATTEMPTS, (int) $row['attempts']);
        self::assertSame('gave up', $row['last_error']);
    }

    public function testJobsStuckInRunningAreReleased(): void
    {
        $this->outbox('2026-10-05T00:00Z')->enqueue('crashed', []);
        $this->outbox('2026-10-05T00:00Z')->claimDue(1);

        self::assertSame([], $this->outbox('2026-10-05T00:14Z')->claimDue(1));
        self::assertCount(1, $this->outbox('2026-10-05T00:15Z')->claimDue(1));
    }

    public function testWorkerRunsHandlersAndRecordsOutcomes(): void
    {
        $outbox = $this->outbox('2026-10-05T00:00Z');
        $outbox->enqueue('ok', ['x' => 1]);
        $outbox->enqueue('boom', []);
        $outbox->enqueue('unknown', []);
        $recorder = new class implements JobHandler {
            /** @var list<array<string, mixed>> */
            public array $seen = [];

            public function handle(array $payload): void
            {
                $this->seen[] = $payload;
            }
        };
        $worker = new OutboxWorker($outbox, [
            'ok' => $recorder,
            'boom' => new class implements JobHandler {
                public function handle(array $payload): void
                {
                    throw new RuntimeException('SMTP refused');
                }
            },
        ]);

        $result = $worker->run(10);

        self::assertSame([['x' => 1]], $recorder->seen);
        self::assertSame(1, $result->succeeded);
        self::assertSame(2, $result->failed);
        self::assertSame(['done', 'pending', 'failed'], array_map(
            fn(string $type): string => (string) $this->rowByType($type)['status'],
            ['ok', 'boom', 'unknown'],
        ));
        self::assertSame('SMTP refused', $this->rowByType('boom')['last_error']);
        self::assertStringContainsString('No handler', (string) $this->rowByType('unknown')['last_error']);
    }

    private function outbox(string $now): Outbox
    {
        return new Outbox($this->pdo, new FrozenClock($now));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM outbox_jobs WHERE id = ?');
        $statement->execute([$id]);

        return (array) $statement->fetch();
    }

    /**
     * @return array<string, mixed>
     */
    private function rowByType(string $type): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM outbox_jobs WHERE type = ?');
        $statement->execute([$type]);

        return (array) $statement->fetch();
    }
}
