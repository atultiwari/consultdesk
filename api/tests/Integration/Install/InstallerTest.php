<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Install;

use ConsultDesk\Infra\Config;
use ConsultDesk\Install\Installer;
use ConsultDesk\Install\InstallFailed;
use ConsultDesk\Tests\Integration\IntegrationTestCase;

final class InstallerTest extends IntegrationTestCase
{
    private string $app = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = sys_get_temp_dir() . '/consultdesk-install-' . bin2hex(random_bytes(6));
        mkdir($this->app . '/storage/media', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (['config.php', 'install-code.txt', '.installed'] as $file) {
            if (is_file($this->app . '/' . $file)) {
                unlink($this->app . '/' . $file);
            }
        }
        rmdir($this->app . '/storage/media');
        rmdir($this->app . '/storage');
        rmdir($this->app);
        self::invalidateSchema();
        parent::tearDown();
    }

    public function testChecksTheServerBeforeAnythingElse(): void
    {
        $checks = $this->installer()->requirements();

        self::assertSame(['php', 'pdo_mysql', 'intl', 'sodium', 'gd', 'writable'], array_keys($checks));
        self::assertTrue($checks['php']['ok']);
        self::assertTrue($checks['writable']['ok']);
    }

    public function testASetupCodeInTheAppFolderProvesWhoIsInstalling(): void
    {
        $installer = $this->installer();
        $installer->ensureSetupCode();
        $code = trim((string) file_get_contents($this->app . '/install-code.txt'));

        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->app . '/install-code.txt')), -4));
        $installer->ensureSetupCode();
        self::assertSame($code, trim((string) file_get_contents($this->app . '/install-code.txt')), 'stays the same until used');
        self::assertTrue($installer->codeMatches(strtolower($code)));
        self::assertFalse($installer->codeMatches('AAAA-BBBB-CCCC'));
    }

    public function testInstallsWritesAWorkingPrivateConfigAndLocksItself(): void
    {
        $installer = $this->installer();
        $installer->ensureSetupCode();

        $installer->install($this->form());

        $config = Config::load($this->app . '/config.php', []);
        self::assertSame(['https://book.example.test', 'desk-test-install', 'VRL'], [$config->appUrl, $config->adminPath, $config->bookingPrefix]);
        self::assertSame(32, strlen($config->appKey));
        self::assertSame($this->app, $config->publicPath, 'the web folder is remembered for in-app updates');
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->app . '/config.php')), -4));
        self::assertFileDoesNotExist($this->app . '/install-code.txt');
        self::assertContains('migrations', self::column($this->pdo, 'SHOW TABLES'));
        self::assertTrue($installer->installed());
        unlink($this->app . '/config.php');
        self::assertTrue($installer->installed(), 'stays locked even if config.php goes missing later');

        $this->expectException(InstallFailed::class);
        $installer->install($this->form());
    }

    public function testExplainsWhatsWrongWithoutWritingAnything(): void
    {
        $installer = $this->installer();
        $installer->ensureSetupCode();

        try {
            $installer->install([...$this->form(), 'app_url' => 'not a url', 'admin_path' => 'admin', 'db_password' => 'wrong-password', 'booking_prefix' => 'V']);
            self::fail('should refuse');
        } catch (InstallFailed $e) {
            self::assertSame(['app_url', 'admin_path', 'booking_prefix'], array_keys($e->errors));
        }

        try {
            $installer->install([...$this->form(), 'db_password' => 'wrong-password']);
            self::fail('should refuse a database it cannot reach');
        } catch (InstallFailed $e) {
            self::assertSame(['db_password'], array_keys($e->errors));
        }
        self::assertFileDoesNotExist($this->app . '/config.php');
    }

    public function testDatabaseAndEmailFieldsTakeOnlySafeCharacters(): void
    {
        $installer = $this->installer();
        try {
            $installer->install([...$this->form(), 'db_name' => 'x;unix_socket=/tmp/evil', 'db_host' => 'db host', 'db_port' => '99999', 'embed_sites' => 'not-a-site']);
            self::fail('should refuse');
        } catch (InstallFailed $e) {
            self::assertSame(['db_name', 'db_host', 'db_port', 'embed_sites'], array_keys($e->errors));
        }
        self::assertFileDoesNotExist($this->app . '/config.php');
    }

    public function testWritesTheSitesAllowedToEmbedIntoTheWebFoldersHtaccess(): void
    {
        $htaccess = $this->app . '/.htaccess';
        file_put_contents($htaccess, "# the frame-ancestors list is below\nHeader always set Content-Security-Policy \"default-src 'self'; frame-ancestors 'self'; base-uri 'none'\"\n");

        $this->installer()->allowEmbedding($htaccess, "https://atultiwari.com\nhttps://www.atultiwari.com/ , https://atultiwari.com");

        self::assertStringContainsString("frame-ancestors 'self' https://atultiwari.com https://www.atultiwari.com;", (string) file_get_contents($htaccess));
        try {
            $this->installer()->allowEmbedding($htaccess, 'javascript:alert(1)');
            self::fail('only https origins');
        } catch (InstallFailed $e) {
            self::assertSame(['embed_sites'], array_keys($e->errors));
        } finally {
            unlink($htaccess);
        }
    }

    private function installer(): Installer
    {
        return new Installer($this->app, self::MIGRATIONS_DIR, $this->app);
    }

    /**
     * @return array<string, string>
     */
    private function form(): array
    {
        $db = self::config() ?? self::fail('No test database.');

        return [
            'app_url' => 'https://book.example.test/',
            'admin_path' => 'desk-test-install',
            'booking_prefix' => 'vrl',
            'db_host' => $db->host,
            'db_port' => (string) $db->port,
            'db_name' => $db->name,
            'db_user' => $db->user,
            'db_password' => $db->password,
            'smtp_host' => 'smtp.hostinger.com',
            'smtp_port' => '465',
            'smtp_encryption' => 'ssl',
            'smtp_user' => 'bookings@example.test',
            'smtp_password' => 'placeholder-not-real',
            'mail_from' => 'bookings@example.test',
            'mail_from_name' => 'Bookings',
        ];
    }
}
