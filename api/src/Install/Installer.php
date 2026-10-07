<?php

declare(strict_types=1);

namespace ConsultDesk\Install;

use ConsultDesk\Domain\Booking\BookingRefPrefix;
use ConsultDesk\Infra\Config;
use ConsultDesk\Infra\Db;
use ConsultDesk\Infra\DbConfig;
use ConsultDesk\Infra\Migrator;
use ConsultDesk\Infra\SystemClock;
use InvalidArgumentException;
use PDOException;
use Throwable;

/**
 * The web installer's work, like WordPress's: check the server, prove the person installing can
 * reach the server's files (a setup code written into the app folder), test the database, write
 * config.php with fresh keys, and create the tables. It refuses to run once config.php exists.
 */
final class Installer
{
    private const CODE_FILE = 'install-code.txt';
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const MIN_PHP = '8.1.0';

    public function __construct(
        private readonly string $appDir,
        private readonly ?string $migrationsDir = null,
    ) {}

    public function installed(): bool
    {
        return is_file($this->configPath());
    }

    /**
     * @return array<string, array{label: string, ok: bool, note: string}>
     */
    public function requirements(): array
    {
        $writable = is_writable($this->appDir) && is_writable($this->appDir . '/storage/media');

        return [
            'php' => ['label' => 'PHP ' . self::MIN_PHP . ' or newer', 'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='), 'note' => 'This server has PHP ' . PHP_VERSION . '. In hPanel: Advanced → PHP Configuration.'],
            'pdo_mysql' => ['label' => 'PDO MySQL extension', 'ok' => extension_loaded('pdo_mysql'), 'note' => 'Turn on pdo_mysql in PHP Configuration → PHP extensions.'],
            'intl' => ['label' => 'intl extension', 'ok' => extension_loaded('intl'), 'note' => 'Turn on intl in PHP Configuration → PHP extensions.'],
            'sodium' => ['label' => 'sodium extension', 'ok' => extension_loaded('sodium'), 'note' => 'Turn on sodium in PHP Configuration → PHP extensions.'],
            'gd' => ['label' => 'GD extension (photos and logos)', 'ok' => extension_loaded('gd'), 'note' => 'Turn on gd in PHP Configuration → PHP extensions.'],
            'writable' => ['label' => 'The consultdesk-app folder can be written to', 'ok' => $writable, 'note' => 'In File Manager, give consultdesk-app and consultdesk-app/storage permission 755.'],
        ];
    }

    public function requirementsMet(): bool
    {
        return array_reduce($this->requirements(), static fn(bool $ok, array $check): bool => $ok && $check['ok'], true);
    }

    /**
     * Writes a fresh setup code into the app folder (once); the person installing reads it there.
     */
    public function ensureSetupCode(): void
    {
        $path = $this->appDir . '/' . self::CODE_FILE;
        if (is_file($path) || $this->installed()) {
            return;
        }
        $parts = [];
        for ($p = 0; $p < 3; $p++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $parts[] = $part;
        }
        self::writePrivate($path, implode('-', $parts) . "\n");
    }

    public function codeMatches(string $given): bool
    {
        $path = $this->appDir . '/' . self::CODE_FILE;
        $code = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return $code !== '' && hash_equals($code, strtoupper(trim($given)));
    }

    /**
     * @param array<string, string> $form
     *
     * @return array{admin_url: string, cron_url: string} where to go next
     *
     * @throws InstallFailed
     */
    public function install(array $form): array
    {
        if ($this->installed()) {
            throw new InstallFailed(['install' => 'ConsultDesk is already installed here.']);
        }
        $values = $this->values($form);
        $this->testDatabase($values);
        $this->writeConfig($values);
        try {
            $db = Db::connect(DbConfig::fromEnv($values));
            (new Migrator($db->pdo(), $this->migrationsDir ?? $this->appDir . '/migrations', new SystemClock()))->migrate();
        } catch (Throwable $e) {
            unlink($this->configPath()); // try again from the start
            throw new InstallFailed(['db_name' => 'The tables couldn’t be created: ' . $e->getMessage()]);
        }
        @unlink($this->appDir . '/' . self::CODE_FILE);

        return [
            'admin_url' => $values['APP_URL'] . '/' . $values['ADMIN_PATH'],
            'cron_url' => $values['APP_URL'] . '/api/cron?key=' . $values['CRON_KEY'],
        ];
    }

    /**
     * Lets these sites show booking pages in a frame (embed.js): rewrites frame-ancestors in the web
     * folder's .htaccess. Blank keeps just this site.
     *
     * @throws InstallFailed
     */
    public function allowEmbedding(string $htaccess, string $sites): void
    {
        $origins = [];
        foreach (preg_split('/[\s,]+/', trim($sites)) ?: [] as $site) {
            if ($site === '') {
                continue;
            }
            $origin = rtrim($site, '/');
            if (preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $origin) !== 1) {
                throw new InstallFailed(['embed_sites' => 'Use full https:// addresses, e.g. https://atultiwari.com, one per line.']);
            }
            $origins[strtolower($origin)] = true;
        }
        $contents = is_file($htaccess) ? (string) file_get_contents($htaccess) : '';
        // Only inside the Content-Security-Policy header, never a comment that mentions it.
        $updated = preg_replace('/(Content-Security-Policy "[^"]*?)frame-ancestors [^;"]*;/', '$1frame-ancestors ' . implode(' ', ["'self'", ...array_keys($origins)]) . ';', $contents, 1, $count);
        if ($count !== 1 || $updated === null || file_put_contents($htaccess, $updated) === false) {
            throw new InstallFailed(['embed_sites' => 'Couldn’t update the web folder’s .htaccess; edit its frame-ancestors line by hand.']);
        }
    }

    /**
     * A suggested secret admin path, e.g. desk-7k2m9xq4.
     */
    public static function suggestAdminPath(): string
    {
        return 'desk-' . substr(strtolower(strtr(base64_encode(random_bytes(9)), '+/=', 'xyz')), 0, 10);
    }

    /**
     * The form as config.php values, checked with the same rules the app uses when it starts.
     *
     * @param array<string, string> $form
     *
     * @return array<string, string>
     *
     * @throws InstallFailed
     */
    private function values(array $form): array
    {
        $field = static fn(string $key): string => trim((string) ($form[$key] ?? ''));
        $errors = [];
        $values = [
            'APP_URL' => rtrim($field('app_url'), '/'),
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
            'CRON_KEY' => bin2hex(random_bytes(24)),
            'ADMIN_PATH' => strtolower($field('admin_path')),
            'DB_HOST' => $field('db_host') === '' ? 'localhost' : $field('db_host'),
            'DB_PORT' => $field('db_port') === '' ? '3306' : $field('db_port'),
            'DB_NAME' => $field('db_name'),
            'DB_USER' => $field('db_user'),
            'DB_PASSWORD' => (string) ($form['db_password'] ?? ''),
            'SMTP_HOST' => $field('smtp_host'),
            'SMTP_PORT' => $field('smtp_port') === '' ? '465' : $field('smtp_port'),
            'SMTP_ENCRYPTION' => in_array($field('smtp_encryption'), ['ssl', 'tls', 'none'], true) ? $field('smtp_encryption') : 'ssl',
            'SMTP_USER' => $field('smtp_user'),
            'SMTP_PASSWORD' => (string) ($form['smtp_password'] ?? ''),
            'MAIL_FROM' => $field('mail_from'),
            'MAIL_FROM_NAME' => $field('mail_from_name') === '' ? 'Bookings' : $field('mail_from_name'),
        ];
        if (preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $values['APP_URL']) !== 1) {
            $errors['app_url'] = 'Use the full https:// address of the booking site, without a path, e.g. https://book.example.com.';
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/', $values['ADMIN_PATH']) !== 1 || in_array($values['ADMIN_PATH'], ['api', 'install', 'assets', 'my-bookings'], true)) {
            $errors['admin_path'] = '8–64 lowercase letters, digits or "-", hard to guess.';
        }
        if ($field('booking_prefix') !== '') {
            $prefix = BookingRefPrefix::normalise($field('booking_prefix'));
            if ($prefix === null) {
                $errors['booking_prefix'] = '2–6 letters or digits, starting with a letter, e.g. VRL.';
            } else {
                $values['BOOKING_PREFIX'] = $prefix;
            }
        }
        foreach (['db_name' => 'DB_NAME', 'db_user' => 'DB_USER'] as $key => $name) {
            if ($values[$name] === '') {
                $errors[$key] = 'Required: copy it from hPanel → Databases → MySQL Databases.';
            }
        }
        if (filter_var($values['MAIL_FROM'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['mail_from'] = 'The address emails come from, e.g. bookings@example.com.';
        }
        if ($values['SMTP_HOST'] === '') {
            $errors['smtp_host'] = 'For Hostinger email: smtp.hostinger.com.';
        }
        if ($errors !== []) {
            throw new InstallFailed($errors);
        }
        try {
            Config::load('/nonexistent/config.php', $values);
        } catch (InvalidArgumentException $e) {
            throw new InstallFailed(['install' => $e->getMessage()]);
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     *
     * @throws InstallFailed
     */
    private function testDatabase(array $values): void
    {
        try {
            Db::connect(DbConfig::fromEnv($values));
        } catch (PDOException) {
            throw new InstallFailed(['db_password' => 'Couldn’t connect with these database details. Check the name, user and password in hPanel → Databases.']);
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function writeConfig(array $values): void
    {
        $php = "<?php\n\n// Written by the ConsultDesk installer. Keep this file private: it holds your keys.\n"
            . "// Optional settings (Razorpay, Telegram, Google, PAYMENTS_LIVE…): see config.example.php.\n\n"
            . 'return ' . var_export($values, true) . ";\n";
        self::writePrivate($this->configPath(), $php);
    }

    private function configPath(): string
    {
        return $this->appDir . '/config.php';
    }

    private static function writePrivate(string $path, string $contents): void
    {
        $umask = umask(0o077);
        $written = file_put_contents($path, $contents, LOCK_EX);
        umask($umask);
        if ($written === false) {
            throw new InstallFailed(['install' => 'Couldn’t write ' . basename($path) . ' into the consultdesk-app folder. Check its permissions (755).']);
        }
        chmod($path, 0o600);
    }
}
