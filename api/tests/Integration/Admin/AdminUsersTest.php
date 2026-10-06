<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Telegram\TelegramDirectory;
use ConsultDesk\Tests\Integration\Support\Fixtures;

final class AdminUsersTest extends AdminTestCase
{
    private int $demo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->demo = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo'], openAllWeek: false);
    }

    public function testOwnersInviteUsersWhoChooseTheirOwnPassword(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->admin('POST', '/api/admin/users', ['email' => 'Teacher@Example.test', 'name' => 'New Teacher', 'role' => 'provider', 'provider_id' => $this->demo]);
        self::assertSame(201, $status, json_encode($body) ?: '');
        self::assertSame(['teacher@example.test', 'invited', $this->demo], [$body['data']['email'], $body['data']['status'], $body['data']['provider']['id']]);

        $this->services()->cronRunner()->run();
        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        self::assertSame(['teacher@example.test'], $mail->to);
        self::assertStringContainsString('invited', strtolower($mail->subject));
        self::assertSame(1, preg_match('#' . preg_quote(self::APP_URL . '/' . self::ADMIN_PATH . '/welcome?token=', '#') . '([A-Za-z0-9_-]{43})#', $mail->text, $m));

        self::assertSame(401, $this->login('teacher@example.test', self::throwawayPassword())[0], 'no password until they choose one');
        self::assertSame(200, $this->resetWith($m[1] ?? '', $this->password)[0]);
        [$signedIn, $session] = $this->login('teacher@example.test');
        self::assertSame([200, 'provider'], [$signedIn, $session['data']['user']['role']]);

        $this->login('owner@example.test');
        [, $list] = $this->admin('GET', '/api/admin/users');
        self::assertSame(['owner@example.test' => 'active', 'teacher@example.test' => 'active'], array_column($list['data'], 'status', 'email'));
    }

    public function testInvitesExpireAfterTwoDaysAndCanBeSentAgain(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $id = $this->admin('POST', '/api/admin/users', ['email' => 'admin2@example.test', 'role' => 'admin'])[1]['data']['id'];
        $this->services()->cronRunner()->run();

        $this->at('2026-10-07T00:00:01Z');
        $this->login('owner@example.test');
        self::assertSame('invite_expired', $this->admin('GET', '/api/admin/users')[1]['data'][1]['status']);
        self::assertSame(200, $this->admin('POST', "/api/admin/users/{$id}/invite")[0]);
        $this->services()->cronRunner()->run();
        self::assertCount(2, $this->mailer->sent);

        $ownerId = (int) (self::column($this->pdo, "SELECT id FROM users WHERE email = 'owner@example.test'")[0] ?? 0);
        self::assertSame([409, 'already_active'], $this->codeOf($this->admin('POST', "/api/admin/users/{$ownerId}/invite")));
    }

    public function testResendingTooOftenIsRefusedInsteadOfSilentlyDropped(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $id = $this->admin('POST', '/api/admin/users', ['email' => 'admin2@example.test', 'role' => 'admin'])[1]['data']['id'];
        foreach (range(1, 4) as $_) {
            self::assertSame(200, $this->admin('POST', "/api/admin/users/{$id}/invite")[0]);
        }

        [$status, $body] = $this->admin('POST', "/api/admin/users/{$id}/invite");
        self::assertSame([429, 'too_many_invites'], [$status, $body['error']['code']]);
    }

    public function testNamesCarryNoMarkupOrLinks(): void
    {
        $this->createUser('owner@example.test');
        $id = $this->createUser('admin@example.test', 'admin');
        $this->login('owner@example.test');

        self::assertSame(422, $this->admin('PATCH', "/api/admin/users/{$id}", ['name' => '<b>Admin</b>'])[0]);
        self::assertSame(422, $this->admin('PATCH', '/api/admin/me', ['name' => 'see https://example.test'])[0]);
        self::assertSame(200, $this->admin('PATCH', "/api/admin/users/{$id}", ['name' => null])[0], 'a name can be cleared');
    }

    public function testOnlyOwnersManageUsers(): void
    {
        $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');
        self::assertSame(403, $this->admin('GET', '/api/admin/users')[0]);
        self::assertSame(403, $this->admin('POST', '/api/admin/users', ['email' => 'x@example.test', 'role' => 'owner'])[0]);

        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');
        self::assertSame(403, $this->admin('GET', '/api/admin/users')[0]);
    }

    public function testValidatesNewUsers(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->admin('POST', '/api/admin/users', ['email' => 'owner@example.test', 'role' => 'provider']);
        self::assertSame(422, $status);
        self::assertSame(['email', 'provider_id'], array_keys($body['error']['fields']));
        self::assertSame(422, $this->admin('POST', '/api/admin/users', ['email' => 'x@example.test', 'role' => 'emperor'])[0]);
        self::assertSame(422, $this->admin('POST', '/api/admin/users', ['email' => 'x@example.test', 'role' => 'provider', 'provider_id' => 999999])[0]);

        [, $admin] = $this->admin('POST', '/api/admin/users', ['email' => 'x@example.test', 'role' => 'admin', 'provider_id' => $this->demo]);
        self::assertNull($admin['data']['provider'], 'only provider accounts belong to a provider');
    }

    public function testDisablingSignsOutAndBlocksSignInUntilEnabled(): void
    {
        $this->createUser('owner@example.test');
        $adminId = $this->createUser('admin@example.test', 'admin');
        $this->login('admin@example.test');
        $adminSession = [$this->cookie, $this->csrf];

        $this->login('owner@example.test');
        [$status, $body] = $this->admin('PATCH', "/api/admin/users/{$adminId}", ['disabled' => true]);
        self::assertSame([200, 'disabled'], [$status, $body['data']['status']]);

        [$this->cookie, $this->csrf] = $adminSession;
        self::assertSame(401, $this->admin('GET', '/api/admin/me')[0]);
        self::assertSame(401, $this->login('admin@example.test')[0]);

        $this->login('owner@example.test');
        $this->admin('PATCH', "/api/admin/users/{$adminId}", ['disabled' => false]);
        self::assertSame(200, $this->login('admin@example.test')[0]);
        self::assertSame(['admin.user_disabled', 'admin.user_enabled'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action LIKE 'admin.user_%abled' ORDER BY id"));
    }

    public function testDisablingAlsoCutsTelegramAndOpenLinks(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $id = $this->admin('POST', '/api/admin/users', ['email' => 'admin2@example.test', 'role' => 'admin'])[1]['data']['id'];
        $this->services()->cronRunner()->run();
        preg_match('/token=([A-Za-z0-9_-]{43})/', $this->mailer->sent[0]->text, $m);
        $this->pdo->exec("UPDATE users SET telegram_chat_id = '7770009' WHERE id = {$id}");
        self::assertNotNull((new TelegramDirectory($this->pdo))->actorFor('7770009', $this->demo));

        $this->admin('PATCH', "/api/admin/users/{$id}", ['disabled' => true]);

        self::assertNull((new TelegramDirectory($this->pdo))->actorFor('7770009', $this->demo), 'no more buttons in Telegram');
        self::assertSame(['1'], self::column($this->pdo, "SELECT telegram_chat_id IS NULL FROM users WHERE id = {$id}"));
        self::assertSame(400, $this->resetWith($m[1] ?? '', $this->password)[0], 'the invite no longer works');
    }

    public function testAForgottenPasswordRequestDoesNotSpoilAnInvite(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $this->admin('POST', '/api/admin/users', ['email' => 'invitee@example.test', 'role' => 'admin']);
        $this->services()->cronRunner()->run();
        preg_match('/token=([A-Za-z0-9_-]{43})/', $this->mailer->sent[0]->text, $m);

        foreach (range(1, 4) as $_) {
            $this->call('POST', '/api/admin/password/forgot', ['path' => self::ADMIN_PATH, 'email' => 'invitee@example.test'], '198.51.100.' . random_int(1, 250));
        }
        $this->services()->cronRunner()->run();

        self::assertSame(200, $this->resetWith($m[1] ?? '', $this->password)[0]);
    }

    public function testOwnersCannotLockThemselvesOut(): void
    {
        $ownerId = $this->createUser('owner@example.test');
        $otherOwner = $this->createUser('owner2@example.test');
        $this->login('owner@example.test');

        [$status, $body] = $this->admin('PATCH', "/api/admin/users/{$ownerId}", ['role' => 'admin', 'disabled' => true]);
        self::assertSame(422, $status);
        self::assertSame(['role', 'disabled'], array_keys($body['error']['fields']));

        [, $demoted] = $this->admin('PATCH', "/api/admin/users/{$otherOwner}", ['role' => 'provider', 'provider_id' => $this->demo, 'name' => 'Second Person']);
        self::assertSame(['provider', $this->demo, 'Second Person'], [$demoted['data']['role'], $demoted['data']['provider']['id'], $demoted['data']['name']]);
    }

    public function testEveryoneManagesTheirOwnNameAndPassword(): void
    {
        $this->createUser('demo@example.test', 'provider', $this->demo);
        $this->login('demo@example.test');
        $other = [$this->cookie, $this->csrf];
        $this->login('demo@example.test');

        [, $renamed] = $this->admin('PATCH', '/api/admin/me', ['name' => 'Dr. Demo']);
        self::assertSame('Dr. Demo', $renamed['data']['user']['name']);

        $newPassword = self::throwawayPassword();
        [$wrong, $errors] = $this->admin('POST', '/api/admin/me/password', ['current_password' => 'not-it', 'new_password' => $newPassword]);
        self::assertSame([422, ['current_password']], [$wrong, array_keys($errors['error']['fields'])]);
        self::assertSame(422, $this->admin('POST', '/api/admin/me/password', ['current_password' => $this->password, 'new_password' => 'short'])[0]);
        self::assertSame(200, $this->admin('POST', '/api/admin/me/password', ['current_password' => $this->password, 'new_password' => $newPassword])[0]);

        self::assertSame(200, $this->admin('GET', '/api/admin/me')[0], 'this session stays signed in');
        [$this->cookie, $this->csrf] = $other;
        self::assertSame(401, $this->admin('GET', '/api/admin/me')[0], 'other sessions are signed out');
        self::assertSame(200, $this->login('demo@example.test', $newPassword)[0]);
    }

    /**
     * @param array{int, array<string, mixed>, mixed} $result
     *
     * @return array{int, string}
     */
    private function codeOf(array $result): array
    {
        return [$result[0], (string) ($result[1]['error']['code'] ?? '')];
    }
}
