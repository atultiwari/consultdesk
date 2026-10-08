<?php

declare(strict_types=1);

namespace ConsultDesk\Updates;

use ConsultDesk\Infra\Backup;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Migrator;
use ConsultDesk\Infra\Settings;
use PDO;
use Throwable;
use ZipArchive;

/**
 * Installs a release from inside the app, like WordPress updates itself:
 * back up the database → download the zip and its signature → check the signature against
 * ReleaseKey → unpack → swap the app's code (src, vendor, migrations, bin…) → copy the new web
 * files (keeping .htaccess's embed list) → run database updates.
 *
 * config.php, storage/ (uploads, backups) and the web folder's own settings are never touched. If
 * the code swap or the web files fail, the previous code is put back. The previous code stays in
 * storage/updates/previous-<version> until the next update.
 */
final class Updater
{
    /** Parts of consultdesk-app that belong to the release (everything else is the site's own). */
    private const APP_PARTS = ['src', 'vendor', 'migrations', 'bin', 'http.php', 'config.example.php'];
    private const MAX_ZIP_BYTES = 60 * 1024 * 1024;
    private const LOCK = 'consultdesk_update';

    public function __construct(
        private readonly string $appDir,
        private readonly ?string $publicDir,
        private readonly ReleaseSource $source,
        private readonly Backup $backup,
        private readonly Migrator $migrator,
        private readonly Settings $settings,
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly string $current,
        private readonly string $publicKey = ReleaseKey::PUBLIC_KEY,
    ) {}

    /**
     * Why updating from here isn't possible on this installation, or null when it is.
     */
    public function blocker(): ?string
    {
        if ($this->publicDir === null || !is_file($this->publicDir . '/index.html')) {
            return 'In-app updates work on an installed release (the web folder wasn’t found).';
        }
        if (!class_exists(ZipArchive::class)) {
            return 'The zip PHP extension is needed: turn it on in hPanel → PHP Configuration.';
        }
        foreach ([$this->appDir, $this->appDir . '/storage', $this->publicDir] as $dir) {
            if (!is_writable($dir)) {
                return sprintf('%s can’t be written to; check its permissions (755).', basename($dir));
            }
        }

        return null;
    }

    /**
     * @return array{from: string, to: string, backup: string, migrations: list<string>}
     *
     * @throws UpdateFailed
     */
    public function apply(Release $release): array
    {
        $blocker = $this->blocker();
        if ($blocker !== null) {
            throw new UpdateFailed($blocker);
        }
        if (!UpdateChecker::newer($release->version, $this->current)) {
            throw new UpdateFailed('This version is already installed.');
        }
        if (preg_match('/^[0-9][0-9A-Za-z.\-]{0,30}$/', $release->version) !== 1) {
            throw new UpdateFailed('That release has an unexpected version number.');
        }
        $this->lock();
        $work = sprintf('%s/storage/updates/%s-%s', $this->appDir, $release->version, bin2hex(random_bytes(4)));
        try {
            self::mkdir($work);
            $zip = $work . '/release.zip';
            $this->source->download($release->zipUrl, $zip, self::MAX_ZIP_BYTES);
            $this->source->download($release->signatureUrl, $work . '/release.zip.sig', 4096);
            if (!ReleaseKey::verify($zip, (string) file_get_contents($work . '/release.zip.sig'), $this->publicKey)) {
                throw new UpdateFailed('The download isn’t signed by ConsultDesk’s release key, so it wasn’t installed.');
            }
            $root = $this->unpack($zip, $work . '/unpacked', $release->version);

            $backup = $this->backup->create('before-update');
            $previous = sprintf('%s/storage/updates/previous-%s', $this->appDir, $this->current);
            $this->swapApp($root . '/consultdesk-app', $previous);
            try {
                $this->copyPublic($root . '/public');
            } catch (Throwable $e) {
                $this->restoreApp($previous);
                throw $e instanceof UpdateFailed ? $e : new UpdateFailed('The web files couldn’t be copied: ' . $e->getMessage(), 0, $e);
            }
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $applied = $this->migrator->migrate();
            $result = ['from' => $this->current, 'to' => $release->version, 'backup' => basename($backup), 'migrations' => $applied];
            $this->settings->put(UpdateChecker::KEY, [...$this->settings->get(UpdateChecker::KEY), 'last_update' => [...$result, 'at' => $this->clock->now()->format(DATE_ATOM)]]);

            return $result;
        } finally {
            self::remove($work);
            $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => self::LOCK]);
        }
    }

    /**
     * Unpacks the zip (refusing paths that escape the folder) and checks it's the release it claims.
     *
     * @return string the consultdesk-<version> folder
     */
    private function unpack(string $zipPath, string $to, string $version): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateFailed('The download isn’t a readable zip.');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
                    throw new UpdateFailed('The zip contains an unsafe path.');
                }
            }
            self::mkdir($to);
            if (!$zip->extractTo($to)) {
                throw new UpdateFailed('The zip couldn’t be unpacked.');
            }
        } finally {
            $zip->close();
        }
        $root = $to . '/consultdesk-' . $version;
        $versionFile = $root . '/consultdesk-app/src/Version.php';
        $ok = is_file($root . '/public/index.html') && is_file($root . '/consultdesk-app/http.php')
            && is_file($root . '/consultdesk-app/vendor/autoload.php') && is_file($versionFile)
            && str_contains((string) file_get_contents($versionFile), sprintf("CURRENT = '%s'", $version));
        if (!$ok) {
            throw new UpdateFailed('The release zip isn’t laid out as expected.');
        }

        return $root;
    }

    private function swapApp(string $newApp, string $previous): void
    {
        self::remove($previous);
        self::mkdir($previous);
        $moved = [];
        try {
            foreach (self::APP_PARTS as $part) {
                $current = $this->appDir . '/' . $part;
                if (file_exists($current)) {
                    self::move($current, $previous . '/' . $part);
                    $moved[] = $part;
                }
                if (file_exists($newApp . '/' . $part)) {
                    self::move($newApp . '/' . $part, $current);
                }
            }
        } catch (Throwable $e) {
            $this->restoreApp($previous);
            throw new UpdateFailed('The new code couldn’t be put in place, so the previous code was kept.', 0, $e);
        }
    }

    private function restoreApp(string $previous): void
    {
        foreach (self::APP_PARTS as $part) {
            if (file_exists($previous . '/' . $part)) {
                self::remove($this->appDir . '/' . $part);
                @rename($previous . '/' . $part, $this->appDir . '/' . $part);
            }
        }
    }

    /**
     * New assets first and the page that loads them last, so visitors never get a page whose files
     * aren't there yet. The web folder's .htaccess keeps its frame-ancestors (embed) list.
     */
    private function copyPublic(string $from): void
    {
        $public = (string) $this->publicDir;
        $oldAssets = is_dir($public . '/assets') ? (scandir($public . '/assets') ?: []) : [];
        self::copyTree($from . '/assets', $public . '/assets');
        foreach (scandir($from) ?: [] as $entry) {
            if (in_array($entry, ['.', '..', 'assets', 'index.html', '.htaccess'], true)) {
                continue;
            }
            is_dir($from . '/' . $entry) ? self::copyTree($from . '/' . $entry, $public . '/' . $entry) : self::copyFile($from . '/' . $entry, $public . '/' . $entry);
        }
        $this->mergeHtaccess($from . '/.htaccess', $public . '/.htaccess');
        self::copyFile($from . '/index.html', $public . '/index.html');
        $newAssets = scandir($from . '/assets') ?: [];
        foreach (array_diff($oldAssets, $newAssets, ['.', '..']) as $stale) {
            @unlink($public . '/assets/' . $stale);
        }
    }

    private function mergeHtaccess(string $new, string $current): void
    {
        $contents = (string) file_get_contents($new);
        $existing = is_file($current) ? (string) file_get_contents($current) : '';
        if (preg_match('/frame-ancestors [^;"]*;/', $existing, $m) === 1) {
            $contents = (string) preg_replace('/(Content-Security-Policy "[^"]*?)frame-ancestors [^;"]*;/', '$1' . $m[0], $contents, 1);
        }
        self::write($current, $contents);
    }

    private function lock(): void
    {
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $lock->execute(['name' => self::LOCK]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new UpdateFailed('An update is already running.');
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        self::mkdir($to);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            is_dir($from . '/' . $entry) ? self::copyTree($from . '/' . $entry, $to . '/' . $entry) : self::copyFile($from . '/' . $entry, $to . '/' . $entry);
        }
    }

    private static function copyFile(string $from, string $to): void
    {
        $temp = $to . '.new';
        if (!copy($from, $temp) || !rename($temp, $to)) {
            @unlink($temp);
            throw new UpdateFailed(sprintf('Couldn’t write %s.', basename($to)));
        }
    }

    private static function write(string $path, string $contents): void
    {
        $temp = $path . '.new';
        if (file_put_contents($temp, $contents) === false || !rename($temp, $path)) {
            @unlink($temp);
            throw new UpdateFailed(sprintf('Couldn’t write %s.', basename($path)));
        }
    }

    private static function move(string $from, string $to): void
    {
        if (!rename($from, $to)) {
            throw new UpdateFailed(sprintf('Couldn’t move %s.', basename($from)));
        }
    }

    private static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new UpdateFailed(sprintf('Couldn’t create %s.', basename($dir)));
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
