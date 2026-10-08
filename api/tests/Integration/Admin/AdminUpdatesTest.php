<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Tests\Integration\Updates\FakeSource;
use ConsultDesk\Updates\Release;
use ConsultDesk\Version;

final class AdminUpdatesTest extends AdminTestCase
{
    private FakeSource $fake;
    private ?string $public = null;

    protected function extraEnv(): array
    {
        return [...parent::extraEnv(), ...($this->public === null ? [] : ['PUBLIC_PATH' => $this->public])];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeSource(sys_get_temp_dir());
        $this->releases = $this->fake;
    }

    public function testTheOwnerSeesWhetherAnUpdateIsOutAndWhyItCantInstallHere(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [, $before] = $this->admin('GET', '/api/admin/system/updates');
        self::assertSame([Version::CURRENT, false, false], [$before['data']['current'], $before['data']['available'], $before['data']['can_update']]);
        self::assertStringContainsString('installed release', (string) $before['data']['blocker'], 'local development has no web folder to update');

        $this->fake->latest = new Release('99.0.0', 'Big release', '2026-10-08T00:00:00Z', 'https://github.com/x.zip', 'https://github.com/x.zip.sig', false);
        [, $checked] = $this->admin('POST', '/api/admin/system/updates/check');
        self::assertSame([true, '99.0.0', 'Big release'], [$checked['data']['available'], $checked['data']['latest']['version'], $checked['data']['latest']['notes']]);

        self::assertSame(422, $this->admin('POST', '/api/admin/system/updates/apply', ['password' => 'wrong-guess'])[0]);
        [$status, $refused] = $this->admin('POST', '/api/admin/system/updates/apply', ['password' => $this->password]);
        self::assertSame([422, 'update_failed'], [$status, $refused['error']['code'] ?? null]);
        self::assertSame(['admin.update_failed'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action LIKE 'admin.update%'"));
    }

    public function testOnAnInstalledReleaseTheUpdateButtonIsOffered(): void
    {
        $this->public = sys_get_temp_dir() . '/consultdesk-web-' . bin2hex(random_bytes(4));
        mkdir($this->public);
        file_put_contents($this->public . '/index.html', 'site');
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->fake->latest = new Release('99.0.0', 'Notes', null, 'https://github.com/x.zip', 'https://github.com/x.zip.sig', false);

        [, $status] = $this->admin('POST', '/api/admin/system/updates/check');

        unlink($this->public . '/index.html');
        rmdir($this->public);
        self::assertSame([true, null], [$status['data']['can_update'], $status['data']['blocker']]);
    }

    public function testOnlyTheOwnerManagesUpdates(): void
    {
        $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');

        self::assertSame(403, $this->admin('GET', '/api/admin/system/updates')[0]);
        self::assertSame(403, $this->admin('POST', '/api/admin/system/updates/apply', ['password' => $this->password])[0]);
    }
}
