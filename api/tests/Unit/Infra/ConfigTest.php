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
}
