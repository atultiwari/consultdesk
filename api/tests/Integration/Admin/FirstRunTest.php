<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

/**
 * WordPress-style first run: on a site with no accounts yet, the admin path offers to create the
 * owner. Details can also come from .env, so a reset database comes back with the same owner.
 */
final class FirstRunTest extends AdminTestCase
{
    /** @var array<string, string> */
    private array $env = [];

    protected function extraEnv(): array
    {
        return [...parent::extraEnv(), ...$this->env];
    }

    public function testAFreshSiteOffersToCreateTheOwnerWhoIsThenSignedIn(): void
    {
        [, $entry] = $this->call('GET', '/api/admin/entry/' . self::ADMIN_PATH);
        self::assertSame(['first_run' => true, 'owner' => null, 'needs_setup_key' => false], array_intersect_key($entry['data'], array_flip(['first_run', 'owner', 'needs_setup_key'])));

        [$status, $body, $response] = $this->call('POST', '/api/admin/first-run', ['path' => self::ADMIN_PATH, 'name' => 'Site Owner', 'email' => 'Owner@Example.test', 'password' => $this->password]);
        self::assertSame(200, $status, json_encode($body) ?: '');
        self::assertSame(['owner', 'owner@example.test'], [$body['data']['user']['role'], $body['data']['user']['email']]);
        self::assertStringContainsString('__Host-cd_admin=', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(['admin.first_owner_created'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action LIKE 'admin.first%'"));

        self::assertFalse($this->call('GET', '/api/admin/entry/' . self::ADMIN_PATH)[1]['data']['first_run']);
        [$again] = $this->call('POST', '/api/admin/first-run', ['path' => self::ADMIN_PATH, 'name' => 'Intruder', 'email' => 'intruder@example.test', 'password' => $this->password]);
        self::assertSame(409, $again, 'only ever on a site with no accounts');
        self::assertSame(['1'], self::column($this->pdo, 'SELECT COUNT(*) FROM users'));
    }

    public function testTheFormNeedsTheAdminPathAndAProperPassword(): void
    {
        self::assertSame(404, $this->call('POST', '/api/admin/first-run', ['path' => 'wrong-path-0000', 'name' => 'X Y', 'email' => 'x@example.test', 'password' => $this->password])[0]);
        [$status, $body] = $this->call('POST', '/api/admin/first-run', ['path' => self::ADMIN_PATH, 'name' => 'Site Owner', 'email' => 'owner@example.test', 'password' => 'short']);
        self::assertSame([422, ['password']], [$status, array_keys($body['error']['fields'])]);
        self::assertSame(['0'], self::column($this->pdo, 'SELECT COUNT(*) FROM users'));
    }

    public function testASetupKeyFromEnvIsRequiredWhenSet(): void
    {
        $this->env = ['SETUP_KEY' => 'setup-key-placeholder-0000'];
        self::assertTrue($this->call('GET', '/api/admin/entry/' . self::ADMIN_PATH)[1]['data']['needs_setup_key']);

        $form = ['path' => self::ADMIN_PATH, 'name' => 'Site Owner', 'email' => 'owner@example.test', 'password' => $this->password];
        self::assertSame(403, $this->call('POST', '/api/admin/first-run', [...$form, 'setup_key' => 'wrong-key-placeholder-000'])[0]);
        self::assertSame(200, $this->call('POST', '/api/admin/first-run', [...$form, 'setup_key' => 'setup-key-placeholder-0000'])[0]);
    }

    public function testAnOwnerWithAPasswordInEnvIsCreatedAutomaticallyAndFlagged(): void
    {
        $this->env = ['OWNER_EMAIL' => 'owner@example.test', 'OWNER_NAME' => 'Env Owner', 'OWNER_PASSWORD' => $this->password];

        [, $entry] = $this->call('GET', '/api/admin/entry/' . self::ADMIN_PATH);
        self::assertFalse($entry['data']['first_run']);
        self::assertSame(['Env Owner', 'owner'], [self::column($this->pdo, 'SELECT name FROM users')[0], self::column($this->pdo, 'SELECT role FROM users')[0]]);

        $this->login('owner@example.test');
        [, $system] = $this->admin('GET', '/api/admin/system');
        self::assertContains('owner_password_in_env', $system['data']['warnings']);
    }

    public function testAnOwnerEmailWithoutAPasswordFillsInTheForm(): void
    {
        $this->env = ['OWNER_EMAIL' => 'owner@example.test', 'OWNER_NAME' => 'Env Owner'];

        [, $entry] = $this->call('GET', '/api/admin/entry/' . self::ADMIN_PATH);
        self::assertSame([true, ['email' => 'owner@example.test', 'name' => 'Env Owner']], [$entry['data']['first_run'], $entry['data']['owner']]);
        self::assertSame(['0'], self::column($this->pdo, 'SELECT COUNT(*) FROM users'));
    }
}
