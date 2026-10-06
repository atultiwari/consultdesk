<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

final class AdminSystemTest extends AdminTestCase
{
    public function testShowsVersionUpdatesCronAndOutboxHealth(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->admin('GET', '/api/admin/system');
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $body['data']['version']);
        self::assertSame([], $body['data']['migrations_pending']);
        self::assertSame(['last_run_at' => null, 'healthy' => false], $body['data']['cron']);

        $this->services()->cronRunner()->run();
        $this->pdo->exec("INSERT INTO outbox_jobs (type, payload, status, attempts, available_at, last_error)
            VALUES ('email.booking', '{}', 'failed', 8, '2026-10-04 00:00:00', 'SMTP said no')");

        [, $after] = $this->admin('GET', '/api/admin/system');
        self::assertSame(['last_run_at' => '2026-10-05T00:00:00Z', 'healthy' => true], $after['data']['cron']);
        self::assertSame(1, $after['data']['outbox']['failed']);
        self::assertSame('SMTP said no', $after['data']['outbox']['last_error']);

        $this->at('2026-10-05T00:10Z');
        $this->login('owner@example.test');
        self::assertFalse($this->admin('GET', '/api/admin/system')[1]['data']['cron']['healthy'], 'no run for ten minutes');
    }

    public function testRetriesFailedJobsAndAppliesUpdates(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->pdo->exec("INSERT INTO outbox_jobs (type, payload, status, attempts, available_at) VALUES ('email.booking', '{}', 'failed', 8, '2026-10-04 00:00:00')");

        [$status, $body] = $this->admin('POST', '/api/admin/system/retry-failed');
        self::assertSame([200, 1], [$status, $body['data']['retried']]);
        self::assertSame(['pending'], self::column($this->pdo, 'SELECT status FROM outbox_jobs'));

        self::assertSame([], $this->admin('POST', '/api/admin/system/migrate')[1]['data']['applied']);
        self::assertSame(1, (int) (self::column($this->pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.outbox_retried'")[0] ?? 0));
    }

    public function testOnlyOwnersSeeTheSystem(): void
    {
        $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');

        self::assertSame(403, $this->admin('GET', '/api/admin/system')[0]);
        self::assertSame(403, $this->admin('POST', '/api/admin/system/migrate')[0]);
    }
}
