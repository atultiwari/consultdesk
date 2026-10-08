<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Updates;

use ConsultDesk\Infra\Backup;
use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Infra\Migrator;
use ConsultDesk\Infra\Settings;
use ConsultDesk\Tests\Integration\IntegrationTestCase;
use ConsultDesk\Updates\Release;
use ConsultDesk\Updates\UpdateChecker;
use ConsultDesk\Updates\UpdateFailed;
use ConsultDesk\Updates\Updater;
use ZipArchive;

/**
 * A fake installation (consultdesk-app + web folder) updated from a fake signed release.
 */
final class UpdaterTest extends IntegrationTestCase
{
    private string $root = '';
    private string $app = '';
    private string $public = '';
    /** @var non-empty-string */
    private string $secretKey = 'unset';
    private string $publicKey = '';
    private FakeSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/consultdesk-update-' . bin2hex(random_bytes(6));
        $this->app = $this->root . '/consultdesk-app';
        $this->public = $this->root . '/public_html/book';
        foreach (['src', 'vendor', 'bin', 'storage/media', 'storage/backups'] as $dir) {
            mkdir($this->app . '/' . $dir, 0o777, true);
        }
        mkdir($this->public . '/assets', 0o777, true);
        $this->copyMigrations($this->app . '/migrations');
        file_put_contents($this->app . '/src/Version.php', "<?php // CURRENT = '1.0.0'");
        file_put_contents($this->app . '/vendor/autoload.php', '<?php // old');
        file_put_contents($this->app . '/http.php', '<?php // old');
        file_put_contents($this->app . '/config.php', "<?php return ['APP_URL' => 'mine'];");
        file_put_contents($this->app . '/storage/media/photo.jpg', 'my photo');
        file_put_contents($this->public . '/index.html', 'old page <script src="/assets/old.js">');
        file_put_contents($this->public . '/assets/old.js', 'old');
        file_put_contents($this->public . '/.htaccess', "Header always set Content-Security-Policy \"default-src 'self'; frame-ancestors 'self' https://atultiwari.com; base-uri 'none'\"\n");
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        self::assertNotSame('', $secret);
        $this->secretKey = $secret;
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->source = new FakeSource($this->root . '/downloads');
    }

    protected function tearDown(): void
    {
        self::rmTree($this->root);
        parent::tearDown();
    }

    public function testInstallsASignedReleaseAndKeepsTheSitesOwnFiles(): void
    {
        $result = $this->updater()->apply($this->publish('1.1.0'));

        self::assertSame(['1.0.0', '1.1.0'], [$result['from'], $result['to']]);
        self::assertStringContainsString("CURRENT = '1.1.0'", (string) file_get_contents($this->app . '/src/Version.php'));
        self::assertSame('<?php // new', file_get_contents($this->app . '/vendor/autoload.php'));
        self::assertSame("<?php return ['APP_URL' => 'mine'];", file_get_contents($this->app . '/config.php'), 'settings untouched');
        self::assertSame('my photo', file_get_contents($this->app . '/storage/media/photo.jpg'), 'uploads untouched');
        self::assertSame('new page <script src="/assets/new.js">', file_get_contents($this->public . '/index.html'));
        self::assertFileExists($this->public . '/assets/new.js');
        self::assertFileDoesNotExist($this->public . '/assets/old.js', 'stale assets are cleared');
        self::assertSame('new embed', file_get_contents($this->public . '/embed.js'));
        $htaccess = (string) file_get_contents($this->public . '/.htaccess');
        self::assertStringContainsString('# new rules', $htaccess);
        self::assertStringContainsString("frame-ancestors 'self' https://atultiwari.com;", $htaccess, 'the embed list is kept');
        self::assertStringContainsString("CURRENT = '1.0.0'", (string) file_get_contents($this->app . '/storage/updates/previous-1.0.0/src/Version.php'));
        self::assertCount(1, glob($this->app . '/storage/backups/consultdesk-before-update-*.sql.gz') ?: []);
        self::assertSame('1.1.0', (new Settings($this->pdo))->get(UpdateChecker::KEY)['last_update']['to'] ?? null);
        self::assertSame(['previous-1.0.0'], array_values(array_diff(scandir($this->app . '/storage/updates') ?: [], ['.', '..'])), 'downloads are cleaned up');
    }

    public function testRefusesAnythingNotSignedByTheReleaseKey(): void
    {
        $release = $this->publish('1.1.0');
        file_put_contents($this->root . '/downloads/consultdesk-1.1.0.zip', 'tampered', FILE_APPEND);

        $this->expectRefusal('isn’t signed', fn() => $this->updater()->apply($release));
        $this->assertNothingChanged();
    }

    public function testRefusesUnsafeOrMislabelledZips(): void
    {
        $this->expectRefusal('unsafe path', fn() => $this->updater()->apply($this->publish('1.1.0', ['../escape.txt' => 'x'])));
        $this->expectRefusal('laid out', fn() => $this->updater()->apply($this->publish('1.2.0', [], versionInside: '9.9.9')));
        $this->expectRefusal('already installed', fn() => $this->updater()->apply($this->publish('1.0.0')));
        $this->assertNothingChanged();
    }

    public function testTheCheckerSaysWhenANewerVersionIsOut(): void
    {
        $settings = new Settings($this->pdo);
        $this->source->latest = $this->publish('1.1.0');
        $checker = new UpdateChecker($this->source, $settings, new FrozenClock('2026-10-08T00:00Z'), current: '1.0.0');

        self::assertFalse($checker->status()['available']);
        self::assertSame(1, $checker->checkIfDue());
        self::assertSame([true, '1.1.0'], [$checker->status()['available'], $checker->status()['latest']['version'] ?? null]);
        self::assertSame(0, $checker->checkIfDue(), 'at most every 12 hours');

        $this->source->fail = true;
        self::assertSame('GitHub is down', (new UpdateChecker($this->source, $settings, new FrozenClock('2026-10-09T00:00Z'), current: '1.0.0'))->check()['error']);
    }

    private function updater(): Updater
    {
        $clock = new FrozenClock('2026-10-08T00:00Z');
        $migrator = new Migrator($this->pdo, $this->app . '/migrations', $clock);

        return new Updater(
            $this->app,
            $this->public,
            $this->source,
            new Backup($this->pdo, str_repeat('k', 32), $migrator, $clock, $this->app . '/storage/backups'),
            $migrator,
            new Settings($this->pdo),
            $this->pdo,
            $clock,
            '1.0.0',
            $this->publicKey,
        );
    }

    /**
     * Builds and signs a release zip the way release/build.sh and the workflow do.
     *
     * @param array<string, string> $extra more entries (path => contents)
     */
    private function publish(string $version, array $extra = [], ?string $versionInside = null): Release
    {
        $dir = $this->root . '/downloads';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $zipPath = sprintf('%s/consultdesk-%s.zip', $dir, $version);
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $base = 'consultdesk-' . $version;
        $files = [
            "{$base}/consultdesk-app/src/Version.php" => sprintf("<?php // CURRENT = '%s'", $versionInside ?? $version),
            "{$base}/consultdesk-app/vendor/autoload.php" => '<?php // new',
            "{$base}/consultdesk-app/http.php" => '<?php // new',
            "{$base}/public/index.html" => 'new page <script src="/assets/new.js">',
            "{$base}/public/assets/new.js" => 'new',
            "{$base}/public/embed.js" => 'new embed',
            "{$base}/public/.htaccess" => "# new rules\nHeader always set Content-Security-Policy \"default-src 'self'; frame-ancestors 'self'; base-uri 'none'\"\n",
            ...$extra,
        ];
        foreach (glob(self::MIGRATIONS_DIR . '/*.sql') ?: [] as $migration) {
            $files["{$base}/consultdesk-app/migrations/" . basename($migration)] = (string) file_get_contents($migration);
        }
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        file_put_contents($zipPath . '.sig', base64_encode(sodium_crypto_sign_detached((string) file_get_contents($zipPath), $this->secretKey)));

        return new Release($version, 'Notes', null, $zipPath, $zipPath . '.sig', false);
    }

    private function expectRefusal(string $message, callable $run): void
    {
        try {
            $run();
            self::fail("Expected refusal: {$message}");
        } catch (UpdateFailed $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function assertNothingChanged(): void
    {
        self::assertStringContainsString("CURRENT = '1.0.0'", (string) file_get_contents($this->app . '/src/Version.php'));
        self::assertSame('old page <script src="/assets/old.js">', file_get_contents($this->public . '/index.html'));
        self::assertFileDoesNotExist($this->root . '/escape.txt');
    }

    private function copyMigrations(string $to): void
    {
        mkdir($to, 0o777, true);
        foreach (glob(self::MIGRATIONS_DIR . '/*.sql') ?: [] as $file) {
            copy($file, $to . '/' . basename($file));
        }
    }

    private static function rmTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmTree($path . '/' . $entry);
            }
        }
        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
