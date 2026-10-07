<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use ConsultDesk\Admin\OwnerDefaults;
use ConsultDesk\Calendar\GoogleConfig;
use ConsultDesk\Domain\Booking\BookingRefPrefix;
use ConsultDesk\Payments\Razorpay\RazorpayCredentials;
use ConsultDesk\Payments\Razorpay\RazorpayEnvKeys;
use ConsultDesk\Telegram\TelegramConfig;
use InvalidArgumentException;

/**
 * Runtime configuration. The installer (Phase 8) writes config.php returning an array of the same
 * keys as the environment variables; anything missing there falls back to the environment.
 */
final class Config
{
    private const APP_KEY_PREFIX = 'base64:';
    private const MIN_CRON_KEY_LENGTH = 32;
    private const MIN_SETUP_KEY_LENGTH = 16;
    /** The throwaway value in docker-compose.yml; a public site must never run with it. */
    private const LOCAL_DEV_SECRETS = [
        'CRON_KEY' => 'local-dev-cron-key-not-secret-0000',
    ];

    private function __construct(
        public readonly string $appUrl,
        #[\SensitiveParameter]
        public readonly string $appKey,
        #[\SensitiveParameter]
        public readonly string $cronKey,
        public readonly bool $debug,
        public readonly DbConfig $db,
        public readonly MailConfig $mail,
        /** @var list<string> */
        public readonly array $trustedProxies = [],
        public readonly ?string $trustedProxyHeader = null,
        public readonly ?TelegramConfig $telegram = null,
        public readonly ?GoogleConfig $google = null,
        /** The secret first path segment of the admin area; null turns the admin area off. */
        public readonly ?string $adminPath = null,
        /** Where uploaded images live: outside the web root, served by GET /api/media/{name}. */
        public readonly string $mediaPath = '',
        /** Where safety backups are kept: outside the web root. */
        public readonly string $backupPath = '',
        /** The organisation's default Razorpay keys; keys saved in the admin panel override them. */
        public readonly ?RazorpayCredentials $razorpay = null,
        /** The first owner's details, used on a fresh install. */
        public readonly ?OwnerDefaults $owner = null,
        /** When set, the first-run "create the owner" form also asks for this key. */
        #[\SensitiveParameter]
        public readonly ?string $setupKey = null,
        /** What booking codes start with until staff choose (VRL → VRL-7F3K). */
        public readonly string $bookingPrefix = BookingRefPrefix::FALLBACK,
    ) {}

    /**
     * @param array<string, string> $env
     */
    public static function load(string $configFile, array $env): self
    {
        $values = $env;
        if (is_file($configFile)) {
            $fromFile = require $configFile;
            if (!is_array($fromFile)) {
                throw new InvalidArgumentException('config.php must return an array.');
            }
            foreach ($fromFile as $key => $value) {
                $values[(string) $key] = is_scalar($value) ? (string) $value : '';
            }
        }

        return self::fromValues($values);
    }

    /**
     * @param array<string, string> $v
     */
    private static function fromValues(array $v): self
    {
        $appUrl = rtrim($v['APP_URL'] ?? '', '/');
        if (preg_match('#^https?://[^\s/]+#', $appUrl) !== 1) {
            throw new InvalidArgumentException('APP_URL must be an absolute http(s) URL.');
        }

        if (str_starts_with($appUrl, 'https://')) {
            foreach (self::LOCAL_DEV_SECRETS as $key => $devValue) {
                if (($v[$key] ?? '') === $devValue) {
                    throw new InvalidArgumentException(sprintf('%s is the local development value; generate a real one for this site.', $key));
                }
            }
        }

        $encodedKey = $v['APP_KEY'] ?? '';
        $appKey = str_starts_with($encodedKey, self::APP_KEY_PREFIX)
            ? base64_decode(substr($encodedKey, strlen(self::APP_KEY_PREFIX)), true)
            : false;
        if ($appKey === false || strlen($appKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException('APP_KEY must be "base64:" followed by 32 random bytes.');
        }

        $cronKey = $v['CRON_KEY'] ?? '';
        if (strlen($cronKey) < self::MIN_CRON_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf('CRON_KEY must be at least %d characters.', self::MIN_CRON_KEY_LENGTH));
        }

        $encryption = strtolower($v['SMTP_ENCRYPTION'] ?? 'tls');

        return new self(
            appUrl: $appUrl,
            appKey: $appKey,
            cronKey: $cronKey,
            debug: ($v['APP_DEBUG'] ?? '0') === '1',
            db: DbConfig::fromEnv($v),
            mail: new MailConfig(
                host: $v['SMTP_HOST'] ?? '',
                port: (int) ($v['SMTP_PORT'] ?? 587),
                username: ($v['SMTP_USER'] ?? '') === '' ? null : $v['SMTP_USER'],
                password: ($v['SMTP_PASSWORD'] ?? '') === '' ? null : $v['SMTP_PASSWORD'],
                encryption: in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null,
                fromEmail: $v['MAIL_FROM'] ?? '',
                fromName: $v['MAIL_FROM_NAME'] ?? 'ConsultDesk',
            ),
            trustedProxies: array_values(array_filter(array_map('trim', explode(',', $v['TRUSTED_PROXIES'] ?? '')))),
            trustedProxyHeader: ($v['TRUSTED_PROXY_HEADER'] ?? '') === '' ? null : $v['TRUSTED_PROXY_HEADER'],
            telegram: TelegramConfig::fromValues($v),
            google: GoogleConfig::fromValues($v, $appUrl),
            adminPath: self::adminPath($v['ADMIN_PATH'] ?? '', $appUrl),
            mediaPath: rtrim(($v['MEDIA_PATH'] ?? '') === '' ? dirname(__DIR__, 2) . '/storage/media' : $v['MEDIA_PATH'], '/'),
            backupPath: rtrim(($v['BACKUP_PATH'] ?? '') === '' ? dirname(__DIR__, 2) . '/storage/backups' : $v['BACKUP_PATH'], '/'),
            razorpay: RazorpayEnvKeys::fromValues($v),
            owner: OwnerDefaults::fromValues($v),
            setupKey: self::setupKey($v['SETUP_KEY'] ?? ''),
            bookingPrefix: self::bookingPrefix($v['BOOKING_PREFIX'] ?? ''),
        );
    }

    private static function bookingPrefix(string $value): string
    {
        if (trim($value) === '') {
            return BookingRefPrefix::FALLBACK;
        }

        return BookingRefPrefix::normalise($value)
            ?? throw new InvalidArgumentException('BOOKING_PREFIX must be 2–6 letters or digits, starting with a letter, e.g. VRL.');
    }

    private static function setupKey(#[\SensitiveParameter] string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (strlen($value) < self::MIN_SETUP_KEY_LENGTH || preg_match('/\s/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('SETUP_KEY must be at least %d characters without spaces.', self::MIN_SETUP_KEY_LENGTH));
        }

        return $value;
    }

    /**
     * The admin area needs HTTPS so its cookie can be Secure and __Host-; plain HTTP is allowed only
     * on a local machine.
     */
    private static function adminPath(string $value, string $appUrl): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
        if (!str_starts_with($appUrl, 'https://') && !$local) {
            throw new InvalidArgumentException('APP_URL must start with https:// when the admin area is on (ADMIN_PATH).');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/', $value) !== 1 || in_array($value, ['api', 'embed-js', 'install', 'assets', 'my-bookings'], true)) {
            throw new InvalidArgumentException('ADMIN_PATH must be 8–64 lowercase letters, digits or "-", and hard to guess.');
        }

        return $value;
    }
}
