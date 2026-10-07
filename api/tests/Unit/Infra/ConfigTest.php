<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Infra;

use ConsultDesk\Infra\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function env(): array
    {
        return [
            'APP_URL' => 'https://book.example.test/',
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'CRON_KEY' => str_repeat('c', 32),
            'DB_HOST' => 'db',
            'DB_NAME' => 'consultdesk',
            'DB_USER' => 'consultdesk',
            'DB_PASSWORD' => 'placeholder',
            'SMTP_HOST' => 'mailpit',
            'SMTP_PORT' => '1025',
            'SMTP_ENCRYPTION' => 'none',
            'MAIL_FROM' => 'bookings@example.test',
            'MAIL_FROM_NAME' => 'ConsultDesk',
        ];
    }

    public function testReadsEnvironmentWhenThereIsNoConfigFile(): void
    {
        $config = Config::load('/nonexistent/config.php', self::env());

        self::assertSame('https://book.example.test', $config->appUrl, 'trailing slash is trimmed');
        self::assertSame(str_repeat('k', 32), $config->appKey);
        self::assertSame('db', $config->db->host);
        self::assertSame(1025, $config->mail->port);
        self::assertNull($config->mail->encryption);
        self::assertSame('bookings@example.test', $config->mail->fromEmail);
        self::assertFalse($config->debug);
    }

    public function testConfigFileWinsOverEnvironment(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cdcfg') . '.php';
        file_put_contents($file, '<?php return ' . var_export(['APP_URL' => 'https://from-file.example.test', 'APP_DEBUG' => '1'], true) . ';');

        try {
            $config = Config::load($file, self::env());
        } finally {
            unlink($file);
        }

        self::assertSame('https://from-file.example.test', $config->appUrl);
        self::assertTrue($config->debug);
        self::assertSame('db', $config->db->host, 'missing keys fall back to the environment');
    }

    public function testRefusesTheCommittedLocalDevSecretsOnAnHttpsSite(): void
    {
        $dev = ['CRON_KEY' => 'local-dev-cron-key-not-secret-0000'];
        foreach ($dev as $key => $value) {
            try {
                Config::load('/nonexistent/config.php', array_merge(self::env(), [$key => $value]));
                self::fail("Expected the dev {$key} to be refused.");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }

        $local = Config::load('/nonexistent/config.php', array_merge(self::env(), $dev, ['APP_URL' => 'http://localhost:5173']));
        self::assertSame('http://localhost:5173', $local->appUrl);
    }

    public function testTheAdminAreaNeedsHttpsExceptOnALocalMachine(): void
    {
        $admin = ['ADMIN_PATH' => 'desk-7q2x-placeholder'];
        self::assertSame('desk-7q2x-placeholder', Config::load('/nonexistent/config.php', array_merge(self::env(), $admin))->adminPath);
        self::assertSame('desk-7q2x-placeholder', Config::load('/nonexistent/config.php', array_merge(self::env(), $admin, ['APP_URL' => 'http://localhost:5173']))->adminPath);

        $this->expectException(InvalidArgumentException::class);
        Config::load('/nonexistent/config.php', array_merge(self::env(), $admin, ['APP_URL' => 'http://book.example.com']));
    }

    public function testReadsTrustedProxySettings(): void
    {
        $config = Config::load('/nonexistent/config.php', array_merge(self::env(), [
            'TRUSTED_PROXIES' => '10.0.0.0/8, 192.0.2.10',
            'TRUSTED_PROXY_HEADER' => 'CF-Connecting-IP',
        ]));

        self::assertSame(['10.0.0.0/8', '192.0.2.10'], $config->trustedProxies);
        self::assertSame('CF-Connecting-IP', $config->trustedProxyHeader);
        self::assertSame([], Config::load('/nonexistent/config.php', self::env())->trustedProxies);
    }

    public function testTelegramIsOptionalAndValidatedWhenConfigured(): void
    {
        self::assertNull(Config::load('/nonexistent/config.php', self::env())->telegram);

        $config = Config::load('/nonexistent/config.php', array_merge(self::env(), [
            'TELEGRAM_BOT_TOKEN' => '123456789:' . str_repeat('A', 35),
            'TELEGRAM_WEBHOOK_SECRET' => str_repeat('s', 40),
            'TELEGRAM_BOT_USERNAME' => '@ConsultDeskDemoBot',
        ]));
        self::assertNotNull($config->telegram);
        self::assertSame('ConsultDeskDemoBot', $config->telegram->botUsername);

        foreach ([
            ['TELEGRAM_BOT_TOKEN' => 'not-a-token', 'TELEGRAM_WEBHOOK_SECRET' => str_repeat('s', 40)],
            ['TELEGRAM_BOT_TOKEN' => '123456789:' . str_repeat('A', 35), 'TELEGRAM_WEBHOOK_SECRET' => 'short'],
            ['TELEGRAM_BOT_TOKEN' => '123456789:' . str_repeat('A', 35)],
        ] as $bad) {
            try {
                Config::load('/nonexistent/config.php', array_merge(self::env(), $bad));
                self::fail('Expected invalid Telegram settings to be rejected.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('TELEGRAM_', $e->getMessage());
            }
        }
    }

    public function testRejectsAWeakOrMissingKey(): void
    {
        foreach (['APP_KEY' => 'base64:' . base64_encode('short'), 'CRON_KEY' => 'short', 'APP_URL' => 'not a url'] as $key => $value) {
            try {
                Config::load('/nonexistent/config.php', array_merge(self::env(), [$key => $value]));
                self::fail("Expected {$key} to be rejected.");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function testRazorpayKeysAndTheOwnerCanComeFromTheEnvironment(): void
    {
        $config = Config::load('/nonexistent/config.php', [
            ...self::env(),
            'RAZORPAY_KEY_ID' => 'rzp_test_' . str_repeat('E', 14),
            'RAZORPAY_KEY_SECRET' => str_repeat('s', 24),
            'RAZORPAY_WEBHOOK_SECRET' => str_repeat('w', 32),
            'OWNER_EMAIL' => ' Owner@Example.test ',
            'OWNER_NAME' => 'Site Owner',
            'OWNER_PASSWORD' => str_repeat('p', 12),
            'SETUP_KEY' => str_repeat('k', 24),
        ]);

        self::assertNotNull($config->razorpay);
        self::assertSame('rzp_test_' . str_repeat('E', 14), $config->razorpay->keyId);
        self::assertSame(str_repeat('w', 32), $config->razorpay->webhookSecret);
        self::assertNull($config->razorpay->providerId);
        self::assertSame(['owner@example.test', 'Site Owner', true], [$config->owner?->email, $config->owner?->name, $config->owner?->password !== null]);
        self::assertSame(str_repeat('k', 24), $config->setupKey);

        $plain = Config::load('/nonexistent/config.php', self::env());
        self::assertSame([null, null, null], [$plain->razorpay, $plain->owner, $plain->setupKey]);
    }

    public function testRejectsEnvironmentDefaultsThatCannotWork(): void
    {
        $bad = [
            'RAZORPAY_KEY_ID' => ['RAZORPAY_KEY_ID' => 'rzp_live_' . str_repeat('E', 14), 'RAZORPAY_KEY_SECRET' => str_repeat('s', 24)],
            'RAZORPAY_KEY_SECRET' => ['RAZORPAY_KEY_ID' => 'rzp_test_' . str_repeat('E', 14)],
            'RAZORPAY_WEBHOOK_SECRET' => ['RAZORPAY_KEY_ID' => 'rzp_test_' . str_repeat('E', 14), 'RAZORPAY_KEY_SECRET' => str_repeat('s', 24), 'RAZORPAY_WEBHOOK_SECRET' => 'short'],
            'OWNER_EMAIL' => ['OWNER_EMAIL' => 'not-an-email'],
            'OWNER_PASSWORD' => ['OWNER_EMAIL' => 'owner@example.test', 'OWNER_PASSWORD' => 'short'],
            'SETUP_KEY' => ['SETUP_KEY' => 'short'],
        ];
        foreach ($bad as $key => $values) {
            try {
                Config::load('/nonexistent/config.php', [...self::env(), ...$values]);
                self::fail("{$key} should be rejected");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function testLiveRazorpayKeysNeedPaymentsLive(): void
    {
        $live = ['RAZORPAY_KEY_ID' => 'rzp_live_' . str_repeat('L', 14), 'RAZORPAY_KEY_SECRET' => str_repeat('s', 24)];
        try {
            Config::load('/nonexistent/config.php', [...self::env(), ...$live]);
            self::fail('live keys without PAYMENTS_LIVE must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('PAYMENTS_LIVE', $e->getMessage());
        }

        $config = Config::load('/nonexistent/config.php', [...self::env(), ...$live, 'PAYMENTS_LIVE' => '1']);
        self::assertTrue($config->paymentsLive);
        self::assertFalse($config->razorpay?->isTestMode());
        self::assertFalse(Config::load('/nonexistent/config.php', self::env())->paymentsLive);
    }
}
