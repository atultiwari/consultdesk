<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Http\AppFactory;
use ConsultDesk\Tests\Integration\Support\Fixtures;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

final class BackupTest extends AdminTestCase
{
    private string $backups = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->backups = sys_get_temp_dir() . '/consultdesk-backups-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backups . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->backups)) {
            rmdir($this->backups);
        }
        parent::tearDown();
    }

    protected function extraEnv(): array
    {
        return [...parent::extraEnv(), 'BACKUP_PATH' => $this->backups];
    }

    public function testTheOwnerDownloadsASignedBackupAfterConfirmingTheirPassword(): void
    {
        $this->createUser('owner@example.test');
        Fixtures::provider($this->pdo, ['slug' => 'kept', 'name' => 'Kept Teacher']);
        $this->login('owner@example.test');

        [$wrong, $body] = $this->admin('POST', '/api/admin/system/backup', ['password' => 'not-the-password']);
        self::assertSame([422, ['password']], [$wrong, array_keys($body['error']['fields'] ?? [])]);

        $response = $this->admin('POST', '/api/admin/system/backup', ['password' => $this->password])[2];
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/gzip', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/attachment; filename="consultdesk-backup-\d{8}-\d{6}-[0-9a-f]{4}\.sql\.gz"/', $response->getHeaderLine('Content-Disposition'));
        $sql = (string) gzdecode((string) $response->getBody());
        self::assertStringStartsWith("-- consultdesk-backup 1\n", $sql);
        self::assertStringContainsString("'Kept Teacher'", $sql);
        self::assertMatchesRegularExpression('/-- signature: [0-9a-f]{64}\n$/', $sql);
        self::assertStringNotContainsString('INSERT INTO `sessions`', $sql, 'sign-in sessions are left out');
        self::assertSame(['admin.backup_downloaded'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action LIKE 'admin.backup%'"));
    }

    public function testOnlyTheOwnerCanBackUpOrRestore(): void
    {
        $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');

        self::assertSame(403, $this->admin('POST', '/api/admin/system/backup', ['password' => $this->password])[0]);
        self::assertSame(403, $this->restore('x', $this->password, 'RESTORE')->getStatusCode());
    }

    public function testRestoringPutsEverythingBackAndKeepsASafetyCopy(): void
    {
        $this->createUser('owner@example.test');
        Fixtures::provider($this->pdo, ['slug' => 'kept', 'name' => 'Kept Teacher']);
        $this->login('owner@example.test');
        $backup = (string) $this->admin('POST', '/api/admin/system/backup', ['password' => $this->password])[2]->getBody();

        $this->pdo->exec("DELETE FROM providers WHERE slug = 'kept'");
        Fixtures::provider($this->pdo, ['slug' => 'later', 'name' => 'Added Later']);

        self::assertSame(422, $this->restore($backup, $this->password, 'restore please')->getStatusCode(), 'needs the typed confirmation');
        $response = $this->restore($backup, $this->password, 'RESTORE');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::invalidateSchema();

        self::assertSame(['kept'], self::column($this->pdo, 'SELECT slug FROM providers'));
        self::assertCount(1, glob($this->backups . '/consultdesk-before-restore-*.sql.gz') ?: [], 'a safety copy of what was replaced');
        self::assertSame([], glob($this->backups . '/consultdesk-backup-*') ?: [], 'downloads are not kept on the server');
        self::assertSame(['admin.backup_restored'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action = 'admin.backup_restored'"));
    }

    public function testAChangedOrForeignBackupIsRefusedWithoutTouchingTheDatabase(): void
    {
        $this->createUser('owner@example.test');
        Fixtures::provider($this->pdo, ['slug' => 'kept', 'name' => 'Kept Teacher']);
        $this->login('owner@example.test');
        $backup = (string) gzdecode((string) $this->admin('POST', '/api/admin/system/backup', ['password' => $this->password])[2]->getBody());

        $tampered = (string) gzencode(str_replace("'Kept Teacher'", "'Evil Teacher'", $backup));
        $response = $this->restore($tampered, $this->password, 'RESTORE');
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_backup', json_decode((string) $response->getBody(), true)['error']['code'] ?? null);

        $arbitrary = (string) gzencode("-- consultdesk-backup 1\nDROP TABLE providers;\n-- signature: " . str_repeat('0', 64) . "\n");
        self::assertSame(422, $this->restore($arbitrary, $this->password, 'RESTORE')->getStatusCode());
        self::assertSame(['Kept Teacher'], self::column($this->pdo, 'SELECT name FROM providers'));
    }

    public function testWrongPasswordsLockBackupsForTheAccountAndAreRecorded(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->admin('POST', '/api/admin/system/backup', ['password' => 'wrong-guess-' . $i])[0]);
        }
        self::assertSame(429, $this->admin('POST', '/api/admin/system/backup', ['password' => $this->password])[0], 'even the right one, once locked');
        self::assertSame(['5'], self::column($this->pdo, "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.reauth_failed'"));
    }

    public function testARestoreThatFailsPartWayPutsThePreviousDataBack(): void
    {
        $this->createUser('owner@example.test');
        Fixtures::provider($this->pdo, ['slug' => 'kept', 'name' => 'Kept Teacher']);
        $this->login('owner@example.test');
        $body = "-- consultdesk-backup 1\n-- schema: \nSET FOREIGN_KEY_CHECKS = 0;\nTHIS IS NOT SQL;\n";
        $key = hash_hmac('sha256', 'consultdesk-backup-signing', str_repeat('t', 32), true);
        $signed = (string) gzencode($body . '-- signature: ' . hash_hmac('sha256', $body, $key) . "\n");

        $response = $this->restore($signed, $this->password, 'RESTORE');
        self::invalidateSchema();

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('restore_failed', json_decode((string) $response->getBody(), true)['error']['code'] ?? null);
        self::assertSame(['Kept Teacher'], self::column($this->pdo, 'SELECT name FROM providers'));
    }

    private function restore(string $file, string $password, string $confirm): ResponseInterface
    {
        $upload = new UploadedFile((new StreamFactory())->createStream($file), 'backup.sql.gz', 'application/gzip', strlen($file));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/system/restore', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withHeader('Cookie', $this->cookie)
            ->withHeader('X-CSRF-Token', $this->csrf)
            ->withParsedBody(['password' => $password, 'confirm' => $confirm])
            ->withUploadedFiles(['file' => $upload]);

        return AppFactory::create($this->services())->handle($request);
    }
}
