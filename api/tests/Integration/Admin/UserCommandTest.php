<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Admin\AdminUsers;
use ConsultDesk\Admin\Passwords;
use ConsultDesk\Admin\Sessions;
use ConsultDesk\Admin\UserCommand;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Tests\Integration\IntegrationTestCase;
use ConsultDesk\Tests\Integration\Support\Fixtures;

final class UserCommandTest extends IntegrationTestCase
{
    private string $output = '';
    /** @var list<string> */
    private array $passwords = [];

    public function testCreatesAnOwnerWithAPromptedPassword(): void
    {
        $this->passwords = ['a long enough password', 'a long enough password'];

        self::assertSame(0, $this->cli('create', '--email=Owner@Example.test', '--role=owner', '--name=Site Owner'));

        self::assertSame(['owner@example.test', 'Site Owner', 'owner'], [$this->scalar('SELECT email FROM users'), $this->scalar('SELECT name FROM users'), $this->scalar('SELECT role FROM users')]);
        self::assertTrue(password_verify('a long enough password', (string) $this->scalar('SELECT password_hash FROM users')));
        self::assertStringContainsString('Created owner owner@example.test', $this->output);
        self::assertSame('admin.user_created', $this->scalar('SELECT action FROM audit_log'));
    }

    public function testProviderAccountsNeedAnExistingProvider(): void
    {
        Fixtures::provider($this->pdo, ['slug' => 'demo']);
        $this->passwords = ['a long enough password', 'a long enough password'];
        self::assertSame(1, $this->cli('create', '--email=p@example.test', '--role=provider'));
        self::assertStringContainsString('--provider', $this->output);
        self::assertSame(1, $this->cli('create', '--email=p@example.test', '--role=provider', '--provider=ghost'));

        self::assertSame(0, $this->cli('create', '--email=p@example.test', '--role=provider', '--provider=demo'));
        self::assertSame(1, $this->cli('create', '--email=p@example.test', '--role=admin'), 'emails are unique');
    }

    public function testRejectsShortOrMismatchedPasswordsAndBadInput(): void
    {
        $this->passwords = ['short', 'short'];
        self::assertSame(1, $this->cli('create', '--email=a@example.test', '--role=owner'));
        $this->passwords = ['a long enough password', 'a different password!'];
        self::assertSame(1, $this->cli('create', '--email=b@example.test', '--role=owner'));
        self::assertSame(1, $this->cli('create', '--email=not-an-email', '--role=owner'));
        self::assertSame(1, $this->cli('create', '--email=a@example.test', '--role=emperor'));
        self::assertSame(1, $this->cli('explode'));
        self::assertStringContainsString('Usage', $this->output);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testResetPasswordSignsOutEverywhere(): void
    {
        $id = Fixtures::user($this->pdo, 'admin', null, 'admin@example.test');
        $this->pdo->exec("INSERT INTO sessions (id, user_id, csrf_token_hash, created_at, last_seen_at, expires_at)
            VALUES (REPEAT('a', 64), {$id}, REPEAT('b', 64), '2026-10-05 00:00:00', '2026-10-05 00:00:00', '2026-10-05 08:00:00')");
        $this->passwords = ['a brand new password', 'a brand new password'];

        self::assertSame(0, $this->cli('reset-password', '--email=admin@example.test'));
        self::assertTrue(password_verify('a brand new password', (string) $this->scalar("SELECT password_hash FROM users WHERE id = {$id}")));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sessions'));
        self::assertSame(1, $this->cli('reset-password', '--email=nobody@example.test'));
    }

    public function testListsUsers(): void
    {
        $provider = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo']);
        Fixtures::user($this->pdo, 'owner', null, 'owner@example.test');
        Fixtures::user($this->pdo, 'provider', $provider, 'demo@example.test');

        self::assertSame(0, $this->cli('list'));
        self::assertMatchesRegularExpression('/owner@example\.test\s+owner/', $this->output);
        self::assertMatchesRegularExpression('/demo@example\.test\s+provider\s+demo/', $this->output);
    }

    private function scalar(string $sql): mixed
    {
        return self::column($this->pdo, $sql)[0] ?? null;
    }

    /**
     * @phpstan-impure
     */
    private function cli(string ...$args): int
    {
        $this->output = '';
        $clock = new FrozenClock('2026-10-05T00:00Z');
        $users = new AdminUsers($this->pdo, $clock);
        $command = new UserCommand(
            $this->pdo,
            $users,
            new Passwords(),
            new Sessions($this->pdo, $clock, $users, str_repeat('k', 32)),
            new AuditLog($this->pdo, $clock),
            function (string $prompt): string {
                return array_shift($this->passwords) ?? '';
            },
            function (string $text): void {
                $this->output .= $text;
            },
        );

        return $command->run(array_values($args));
    }
}
