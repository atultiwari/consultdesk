<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Infra;

use ConsultDesk\Infra\Db;
use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Infra\Migrator;
use ConsultDesk\Tests\Integration\IntegrationTestCase;
use RuntimeException;

final class MigratorTest extends IntegrationTestCase
{
    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            array_map('unlink', glob($this->tmpDir . '/*') ?: []);
            rmdir($this->tmpDir);
        }
        // These tests rebuild tables, so give the next test a clean, fully migrated schema.
        self::invalidateSchema();
    }

    public function testAppliesEveryMigrationOnceAndRecordsIt(): void
    {
        self::dropAllTables($this->pdo);
        $migrator = new Migrator($this->pdo, self::MIGRATIONS_DIR, new FrozenClock('2026-10-05T00:00Z'));

        self::assertSame(['001_init', '002_notifications_and_rate_limits', '003_customer_email_index', '004_telegram', '005_google_calendar', '006_admin', '007_admin_settings', '008_razorpay', '009_coupons', '010_customer_access', '011_service_highlight', '012_owner_booking_emails'], $migrator->pending());
        self::assertSame(['001_init', '002_notifications_and_rate_limits', '003_customer_email_index', '004_telegram', '005_google_calendar', '006_admin', '007_admin_settings', '008_razorpay', '009_coupons', '010_customer_access', '011_service_highlight', '012_owner_booking_emails'], $migrator->migrate());
        self::assertSame([], $migrator->pending());
        self::assertSame([], $migrator->migrate(), 'a second run is a no-op');

        $tables = self::column($this->pdo, 'SHOW TABLES');
        foreach (['settings', 'providers', 'users', 'services', 'availability_rules', 'blocked_periods', 'bookings',
            'payment_gateways', 'payment_events', 'oauth_tokens', 'outbox_jobs', 'login_attempts', 'sessions',
            'audit_log', 'migrations', 'rate_limits', 'telegram_link_codes', 'telegram_messages', 'google_oauth_states', 'google_busy_cache', 'password_resets'] as $table) {
            self::assertContains($table, $tables);
        }
        self::assertSame(['2026-10-05 00:00:00'], self::column($this->pdo, "SELECT applied_at FROM migrations WHERE version = '001_init'"));
    }

    public function testIgnoresFilesThatAreNotMigrations(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/002_second.sql', 'CREATE TABLE t_second (id INT);');
        file_put_contents($dir . '/001_first.sql', 'CREATE TABLE t_first (id INT);');
        file_put_contents($dir . '/README.md', 'not sql');
        file_put_contents($dir . '/3_bad_name.sql', 'SELECT 1;');
        self::dropAllTables($this->pdo);

        $applied = (new Migrator($this->pdo, $dir, new FrozenClock('2026-10-05T00:00Z')))->migrate();

        self::assertSame(['001_first', '002_second'], $applied);
    }

    public function testFailingStatementStopsAndIsNotRecorded(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/001_broken.sql', 'CREATE TABLE t_ok (id INT); THIS IS NOT SQL;');
        self::dropAllTables($this->pdo);
        $migrator = new Migrator($this->pdo, $dir, new FrozenClock('2026-10-05T00:00Z'));

        try {
            $migrator->migrate();
            self::fail('Expected the migration to fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('001_broken', $e->getMessage());
        }
        self::assertSame(['001_broken'], $migrator->pending());
    }

    public function testRefusesToRunWhileAnotherRunHoldsTheLock(): void
    {
        $config = self::config();
        self::assertNotNull($config);
        $other = Db::connect($config)->pdo();
        self::assertSame(['1'], self::column($other, "SELECT GET_LOCK('consultdesk_migrate', 0)"));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('already running');
            (new Migrator($this->pdo, self::MIGRATIONS_DIR, new FrozenClock('2026-10-05T00:00Z'), lockTimeoutSeconds: 0))->migrate();
        } finally {
            self::column($other, "SELECT RELEASE_LOCK('consultdesk_migrate')");
        }
    }

    public function testMissingDirectoryIsReported(): void
    {
        $this->expectException(RuntimeException::class);
        (new Migrator($this->pdo, '/nonexistent/migrations', new FrozenClock('2026-10-05T00:00Z')))->pending();
    }

    private function makeTmpDir(): string
    {
        $this->tmpDir = sys_get_temp_dir() . '/cd-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);

        return $this->tmpDir;
    }
}
