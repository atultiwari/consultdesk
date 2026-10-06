<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

final class AuthTest extends AdminTestCase
{
    private string $newPassword = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->newPassword = self::throwawayPassword();
    }

    public function testTheSecretPathIsConfirmedWithoutBeingRevealed(): void
    {
        self::assertSame(200, $this->call('GET', '/api/admin/entry/' . self::ADMIN_PATH)[0]);
        self::assertSame(404, $this->call('GET', '/api/admin/entry/admin')[0]);
        self::assertSame(404, $this->call('GET', '/api/admin/entry/desk-7q2x-placeholdeX')[0]);
    }

    public function testSignInSetsAStrictHttpOnlyCookieAndReturnsTheUser(): void
    {
        $this->createUser('owner@example.test');

        [$status, $body, $response] = $this->login('Owner@Example.test');

        self::assertSame(200, $status);
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Secure', $cookie, 'APP_URL is https');
        self::assertStringStartsWith('__Host-', $cookie);
        self::assertSame('owner', $body['data']['user']['role']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $body['data']['csrf_token']);

        [$meStatus, $me] = $this->admin('GET', '/api/admin/me');
        self::assertSame(200, $meStatus);
        self::assertSame('owner@example.test', $me['data']['user']['email']);
        self::assertSame($this->csrf, $me['data']['csrf_token']);
        self::assertNotNull(self::column($this->pdo, 'SELECT last_login_at FROM users')[0] ?? null);
    }

    public function testWrongPathPasswordOrEmailAllLookTheSame(): void
    {
        $this->createUser('owner@example.test');

        self::assertSame(404, $this->call('POST', '/api/admin/login', ['path' => 'wrong-path-xx', 'email' => 'owner@example.test', 'password' => $this->password])[0]);
        [$s1, $b1] = $this->login('owner@example.test', 'wrong password');
        [$s2, $b2] = $this->login('nobody@example.test');
        self::assertSame([401, 'invalid_credentials'], [$s1, $b1['error']['code']]);
        self::assertSame([401, 'invalid_credentials'], [$s2, $b2['error']['code']]);
        self::assertSame($b1['error']['message'], $b2['error']['message']);
    }

    public function testRepeatedFailuresLockTheAddressAndThePairButNotTheOwnerOnAKnownAddress(): void
    {
        $this->createUser('owner@example.test');
        self::assertSame(200, $this->login('owner@example.test', ip: '198.51.100.99')[0], 'a known address');

        for ($i = 0; $i < 5; $i++) {
            $this->login('owner@example.test', 'wrong', '192.0.2.9');
        }
        [$pair, $body] = $this->login('owner@example.test', ip: '192.0.2.9');
        self::assertSame([429, 'too_many_attempts'], [$pair, $body['error']['code']], 'this address may not keep guessing this account');

        for ($i = 0; $i < 20; $i++) {
            $this->login('owner@example.test', 'wrong', '198.51.100.' . $i);
        }
        self::assertSame(429, $this->login('owner@example.test', ip: '203.0.113.50')[0], 'a spread-out attack locks the account for new addresses');
        self::assertSame(200, $this->login('owner@example.test', ip: '198.51.100.99')[0], 'but not for where the owner has signed in before');

        $this->createUser('second@example.test');
        for ($i = 0; $i < 5; $i++) {
            $this->login('nobody' . $i . '@example.test', 'wrong', '192.0.2.50');
        }
        self::assertSame(429, $this->login('second@example.test', ip: '192.0.2.50')[0], 'the address is locked for every account');

        $this->at('2026-10-05T00:15Z');
        self::assertSame(200, $this->login('owner@example.test', ip: '203.0.113.50')[0], 'locks lift after 15 minutes');
    }

    public function testEveryWriteNeedsTheCsrfToken(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        self::assertSame([403, 'csrf_failed'], $this->codeOf($this->admin('POST', '/api/admin/logout', withCsrf: false)));
        $this->csrf = 'forged-token';
        self::assertSame([403, 'csrf_failed'], $this->codeOf($this->admin('POST', '/api/admin/logout')));
    }

    public function testSignOutEndsTheSessionAndClearsTheCookie(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        [$status, , $response] = $this->admin('POST', '/api/admin/logout');

        self::assertSame(200, $status);
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertSame([401, 'unauthenticated'], $this->codeOf($this->admin('GET', '/api/admin/me')));
    }

    public function testSignOutEverywhereEndsOtherSessions(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $first = [$this->cookie, $this->csrf];
        $this->login('owner@example.test');

        $this->admin('POST', '/api/admin/logout-all');

        [$this->cookie, $this->csrf] = $first;
        self::assertSame(401, $this->admin('GET', '/api/admin/me')[0]);
    }

    public function testSessionsExpireAfterEightIdleHoursAndThirtyDays(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');

        $this->at('2026-10-05T07:59Z');
        self::assertSame(200, $this->admin('GET', '/api/admin/me')[0], 'activity keeps it alive');
        $this->at('2026-10-05T15:58Z');
        self::assertSame(200, $this->admin('GET', '/api/admin/me')[0]);
        $this->at('2026-10-06T00:00Z');
        self::assertSame(401, $this->admin('GET', '/api/admin/me')[0], 'more than 8 hours idle');

        $this->at('2026-10-05T00:00Z');
        $this->login('owner@example.test');
        for ($hour = 6; $hour <= 30 * 24; $hour += 6) {
            $this->at(gmdate('Y-m-d\TH:i\Z', strtotime('2026-10-05T00:00Z') + $hour * 3600));
            $status = $this->admin('GET', '/api/admin/me')[0];
            if ($hour < 30 * 24) {
                self::assertSame(200, $status, "hour {$hour}");
            }
        }
        self::assertSame(401, $status, 'never longer than 30 days');
    }

    public function testEachSignInGetsAFreshSession(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $first = $this->cookie;
        $this->login('owner@example.test');

        self::assertNotSame($first, $this->cookie);
        self::assertSame(2, (int) (self::column($this->pdo, 'SELECT COUNT(*) FROM sessions')[0] ?? 0));
        self::assertSame([], self::column($this->pdo, 'SELECT id FROM sessions WHERE id = :raw', ['raw' => explode('=', $this->cookie)[1] ?? '']), 'only a hash is stored');
    }

    public function testForgottenPasswordEmailsAOneTimeLinkAndSignsEverythingOut(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test');
        $oldSession = [$this->cookie, $this->csrf];

        [$status] = $this->call('POST', '/api/admin/password/forgot', ['path' => self::ADMIN_PATH, 'email' => 'owner@example.test']);
        [$unknown] = $this->call('POST', '/api/admin/password/forgot', ['path' => self::ADMIN_PATH, 'email' => 'nobody@example.test']);
        self::assertSame([200, 200], [$status, $unknown], 'no way to tell which addresses exist');
        self::assertSame(['2'], self::column($this->pdo, "SELECT COUNT(*) FROM outbox_jobs WHERE type = 'email.password_reset'"), 'and both do the same work');
        $this->services()->cronRunner()->run();

        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertSame(['owner@example.test'], $email->to);
        self::assertSame(1, preg_match('#' . preg_quote(self::APP_URL . '/' . self::ADMIN_PATH . '/reset?token=', '#') . '([A-Za-z0-9_-]{43})#', $email->text, $m));
        $token = $m[1] ?? '';

        self::assertSame([422, 'validation_failed'], $this->codeOf($this->resetWith($token, 'short')));
        self::assertSame(200, $this->resetWith($token, $this->newPassword)[0]);
        self::assertSame([400, 'invalid_reset_link'], $this->codeOf($this->resetWith($token, self::throwawayPassword())));

        [$this->cookie, $this->csrf] = $oldSession;
        self::assertSame(401, $this->admin('GET', '/api/admin/me')[0], 'old sessions are signed out');
        self::assertSame(200, $this->login('owner@example.test', $this->newPassword)[0]);
        self::assertSame(['0'], self::column($this->pdo, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'password_resets' AND column_name <> 'token_hash' AND column_name LIKE 'token%'"), 'only a hash of the emailed token is kept');
    }

    public function testANewLinkReplacesOlderOnesAndAFewLinksAnHourAtMost(): void
    {
        $this->createUser('owner@example.test');
        foreach (range(1, 5) as $_) {
            $this->call('POST', '/api/admin/password/forgot', ['path' => self::ADMIN_PATH, 'email' => 'owner@example.test'], '198.51.100.' . random_int(1, 250));
        }
        $this->services()->cronRunner()->run();

        self::assertCount(3, $this->mailer->sent, 'three emails an hour, however often someone asks');
        $tokens = array_map(static fn($mail): string => preg_match('/token=([A-Za-z0-9_-]{43})/', $mail->text, $m) === 1 ? $m[1] : '', $this->mailer->sent);
        self::assertSame([400, 'invalid_reset_link'], $this->codeOf($this->resetWith($tokens[0], $this->newPassword)), 'only the newest link works');
        self::assertSame(200, $this->resetWith($tokens[2], $this->newPassword)[0]);
    }

    public function testSignInsAndResetsAreForgottenAfterADay(): void
    {
        $this->createUser('owner@example.test');
        $this->login('owner@example.test', 'wrong');
        $this->call('POST', '/api/admin/password/forgot', ['path' => self::ADMIN_PATH, 'email' => 'owner@example.test']);
        $this->services()->cronRunner()->run();

        $this->at('2026-10-06T01:00Z');
        $this->services()->cronRunner()->run();

        self::assertSame(['0', '0'], [
            self::column($this->pdo, 'SELECT COUNT(*) FROM login_attempts')[0] ?? null,
            self::column($this->pdo, 'SELECT COUNT(*) FROM password_resets')[0] ?? null,
        ]);
    }

    public function testPasswordsAreTakenExactlyAsTyped(): void
    {
        $this->createUser('owner@example.test');
        $this->call('POST', '/api/admin/password/forgot', ['path' => self::ADMIN_PATH, 'email' => 'owner@example.test']);
        $this->services()->cronRunner()->run();
        preg_match('/token=([A-Za-z0-9_-]{43})/', $this->mailer->sent[0]->text, $m);
        $this->resetWith($m[1] ?? '', "  {$this->newPassword}  ");

        self::assertSame(401, $this->login('owner@example.test', $this->newPassword)[0]);
        self::assertSame(200, $this->login('owner@example.test', "  {$this->newPassword}  ")[0]);
    }

    public function testResetLinksExpireAfterThirtyMinutes(): void
    {
        $this->createUser('owner@example.test');
        $this->call('POST', '/api/admin/password/forgot', ['path' => self::ADMIN_PATH, 'email' => 'owner@example.test']);
        $this->services()->cronRunner()->run();
        preg_match('/token=([A-Za-z0-9_-]{43})/', $this->mailer->sent[0]->text, $m);

        $this->at('2026-10-05T00:30:01Z');

        self::assertSame([400, 'invalid_reset_link'], $this->codeOf($this->resetWith($m[1] ?? '', $this->newPassword)));
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
