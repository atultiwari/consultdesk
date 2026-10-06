<?php

declare(strict_types=1);

namespace ConsultDesk\Bootstrap;

use ConsultDesk\Admin\AdminUsers;
use ConsultDesk\Admin\AuthService;
use ConsultDesk\Admin\InviteEmailHandler;
use ConsultDesk\Admin\LoginThrottle;
use ConsultDesk\Admin\PasswordResetEmailHandler;
use ConsultDesk\Admin\PasswordResets;
use ConsultDesk\Admin\Passwords;
use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\Sessions;
use ConsultDesk\Admin\SiteSetup;
use ConsultDesk\Admin\SystemStatus;
use ConsultDesk\Admin\TelegramLinks;
use ConsultDesk\Admin\UserDirectory;
use ConsultDesk\Calendar\CalendarLinks;
use ConsultDesk\Calendar\CalendarServices;
use ConsultDesk\Calendar\GoogleApi;
use ConsultDesk\Calendar\GoogleBusyCache;
use ConsultDesk\Calendar\GoogleBusyTime;
use ConsultDesk\Calendar\GoogleCalendar;
use ConsultDesk\Calendar\GoogleConnections;
use ConsultDesk\Calendar\GoogleDisconnectedHandler;
use ConsultDesk\Calendar\GoogleOAuth;
use ConsultDesk\Calendar\HttpGoogleApi;
use ConsultDesk\Calendar\NullGoogleApi;
use ConsultDesk\Cron\CronRunner;
use ConsultDesk\Domain\Availability\BusyTimeSource;
use ConsultDesk\Domain\Availability\NoBusyTime;
use ConsultDesk\Domain\Availability\SlotFinder;
use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Domain\Booking\PdoBookingRepository;
use ConsultDesk\Domain\Booking\PdoBookingViews;
use ConsultDesk\Domain\Booking\RandomRefGenerator;
use ConsultDesk\Domain\Catalog\PdoCatalog;
use ConsultDesk\Http\AdminCookie;
use ConsultDesk\Http\ClientIp;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Config;
use ConsultDesk\Infra\Crypto;
use ConsultDesk\Infra\Db;
use ConsultDesk\Infra\ImageStore;
use ConsultDesk\Infra\Migrator;
use ConsultDesk\Infra\RateLimiter;
use ConsultDesk\Infra\Settings;
use ConsultDesk\Infra\SystemClock;
use ConsultDesk\Notify\Mail\BookingEmails;
use ConsultDesk\Notify\Mail\Mailer;
use ConsultDesk\Notify\Mail\PhpMailerMailer;
use ConsultDesk\Notify\NotificationHandlers;
use ConsultDesk\Notify\Outbox;
use ConsultDesk\Notify\OutboxBookingEvents;
use ConsultDesk\Notify\OutboxWorker;
use ConsultDesk\Payments\Razorpay\GatewayKeys;
use ConsultDesk\Payments\Razorpay\HttpRazorpayApi;
use ConsultDesk\Payments\Razorpay\RazorpayApi;
use ConsultDesk\Payments\Razorpay\RazorpayCheckout;
use ConsultDesk\Telegram\HttpTelegramApi;
use ConsultDesk\Telegram\LinkCodes;
use ConsultDesk\Telegram\MessageLog;
use ConsultDesk\Telegram\TelegramApi;
use ConsultDesk\Telegram\TelegramBot;
use ConsultDesk\Telegram\TelegramDirectory;
use ConsultDesk\Telegram\TelegramServices;
use GuzzleHttp\Client;
use PDO;

/**
 * Composition root. Builds each service on first use, so a request only connects to the database
 * or the mail server if it needs to. Tests pass their own database, clock and mailer.
 */
final class AppServices
{
    private ?BookingService $bookingService = null;
    private ?Outbox $outbox = null;
    private ?PdoBookingViews $views = null;

    public function __construct(
        public readonly Config $config,
        private ?Db $db = null,
        private readonly Clock $clock = new SystemClock(),
        private ?Mailer $mailer = null,
        private ?TelegramApi $telegramApi = null,
        private ?GoogleApi $googleApi = null,
        private ?RazorpayApi $razorpayApi = null,
    ) {}

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function db(): Db
    {
        return $this->db ??= Db::connect($this->config->db);
    }

    public function crypto(): Crypto
    {
        return new Crypto($this->config->appKey);
    }

    public function bookingService(): BookingService
    {
        return $this->bookingService ??= new BookingService(
            $this->db(),
            new PdoBookingRepository($this->pdo()),
            $this->clock,
            new RandomRefGenerator(),
            new OutboxBookingEvents($this->outbox()),
            $this->crypto(),
            $this->busyTime(),
        );
    }

    public function bookingViews(): PdoBookingViews
    {
        return $this->views ??= new PdoBookingViews($this->pdo());
    }

    public function catalog(): PdoCatalog
    {
        return new PdoCatalog($this->pdo());
    }

    public function slotFinder(): SlotFinder
    {
        return new SlotFinder(new PdoBookingRepository($this->pdo()), $this->busyTime(), $this->clock);
    }

    public function rateLimiter(): RateLimiter
    {
        return new RateLimiter($this->pdo(), $this->clock, $this->config->appKey);
    }

    public function clientIp(): ClientIp
    {
        return new ClientIp($this->config->trustedProxies, $this->config->trustedProxyHeader);
    }

    public function cronRunner(): CronRunner
    {
        $handlers = NotificationHandlers::build(
            $this->bookingViews(),
            $this->outbox(),
            $this->mailer(),
            new BookingEmails(),
            $this->crypto(),
            $this->config->appUrl,
            $this->telegramServices(),
            $this->clock,
            $this->calendarServices(),
            $this->razorpayCheckout(),
        );
        if ($this->config->adminPath !== null) {
            $handlers[PasswordResets::EMAIL_JOB] = new PasswordResetEmailHandler(
                $this->passwordResets(),
                $this->mailer(),
                $this->config->appUrl,
                $this->config->adminPath,
            );
            $handlers[PasswordResets::INVITE_JOB] = new InviteEmailHandler(
                $this->passwordResets(),
                $this->mailer(),
                $this->catalog()->siteSettings()->orgName,
                $this->config->appUrl,
                $this->config->adminPath,
            );
        }
        $settings = $this->settings();
        $clock = $this->clock;
        $cache = new GoogleBusyCache($this->pdo(), $this->clock);
        $sessions = $this->sessions();
        $throttle = $this->loginThrottle();
        $resets = $this->passwordResets();

        return new CronRunner(
            $this->pdo(),
            $this->bookingService(),
            new OutboxWorker($this->outbox(), $handlers),
            $this->rateLimiter(),
            [
                static fn(): int => $cache->prune(),
                fn(): int => $this->googleOAuth()?->prune() ?? 0,
                static fn(): int => $sessions->prune(),
                static fn(): int => $throttle->prune(),
                static fn(): int => $resets->prune(),
            ],
            static fn() => $settings->put(SystemStatus::CRON_KEY, ['last_run_at' => $clock->now()->format('Y-m-d H:i:s')]),
        );
    }

    public function gatewayKeys(): GatewayKeys
    {
        return new GatewayKeys($this->pdo(), $this->crypto(), $this->clock);
    }

    public function razorpayApi(): RazorpayApi
    {
        return $this->razorpayApi ??= new HttpRazorpayApi(new Client());
    }

    public function razorpayCheckout(): RazorpayCheckout
    {
        return new RazorpayCheckout($this->db(), $this->gatewayKeys(), $this->razorpayApi(), $this->bookingService(), $this->bookingViews(), $this->clock, $this->config->appUrl);
    }

    public function siteSetup(): SiteSetup
    {
        return new SiteSetup($this->db(), $this->settings(), new ProviderSettings($this->pdo()), $this->clock);
    }

    public function settings(): Settings
    {
        return new Settings($this->pdo());
    }

    public function imageStore(): ImageStore
    {
        return new ImageStore($this->config->mediaPath);
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->pdo(), dirname(__DIR__, 2) . '/migrations', $this->clock);
    }

    public function systemStatus(): SystemStatus
    {
        return new SystemStatus($this->pdo(), $this->settings(), $this->migrator(), $this->clock);
    }

    public function telegramLinks(): TelegramLinks
    {
        return new TelegramLinks($this->pdo(), $this->telegramServices(), $this->linkCodes(), $this->config->telegram?->botUsername);
    }

    public function userDirectory(): UserDirectory
    {
        return new UserDirectory($this->pdo(), $this->clock);
    }

    public function auditLog(): AuditLog
    {
        return new AuditLog($this->pdo(), $this->clock);
    }

    public function adminUsers(): AdminUsers
    {
        return new AdminUsers($this->pdo(), $this->clock);
    }

    public function sessions(): Sessions
    {
        return new Sessions($this->pdo(), $this->clock, $this->adminUsers(), $this->config->appKey);
    }

    public function adminCookie(): AdminCookie
    {
        return new AdminCookie(str_starts_with($this->config->appUrl, 'https://'));
    }

    public function authService(): AuthService
    {
        return new AuthService($this->adminUsers(), new Passwords(), $this->sessions(), $this->loginThrottle(), $this->auditLog());
    }

    public function loginThrottle(): LoginThrottle
    {
        return new LoginThrottle($this->pdo(), $this->clock);
    }

    public function passwordResets(): PasswordResets
    {
        return new PasswordResets($this->db(), $this->adminUsers(), new Passwords(), $this->sessions(), $this->outbox(), $this->auditLog(), $this->clock);
    }

    public function telegramServices(): ?TelegramServices
    {
        $telegram = $this->config->telegram;
        if ($telegram === null) {
            return null;
        }
        $this->telegramApi ??= new HttpTelegramApi($telegram->botToken, new Client());

        return new TelegramServices($this->telegramApi, new TelegramDirectory($this->pdo()), new MessageLog($this->pdo(), $this->clock));
    }

    public function telegramBot(): ?TelegramBot
    {
        $telegram = $this->telegramServices();
        if ($telegram === null) {
            return null;
        }

        return new TelegramBot(
            $this->bookingViews(),
            $this->bookingService(),
            $telegram,
            $this->linkCodes(),
            $this->db(),
            $this->clock,
            $this->config->telegram?->botUsername,
        );
    }

    public function linkCodes(): LinkCodes
    {
        return new LinkCodes($this->pdo(), $this->clock);
    }

    public function googleCalendar(): ?GoogleCalendar
    {
        $api = $this->googleApi();
        if ($api === null) {
            return null;
        }

        return new GoogleCalendar($api, $this->googleConnections(), $this->outbox(), $this->clock, $this->config->appKey);
    }

    public function googleOAuth(): ?GoogleOAuth
    {
        $api = $this->googleApi();

        return $api === null ? null : new GoogleOAuth($api, $this->googleConnections(), $this->db(), $this->crypto(), $this->clock);
    }

    public function googleConnections(): GoogleConnections
    {
        return new GoogleConnections($this->pdo(), $this->crypto(), $this->clock);
    }

    /**
     * The deterministic Google event id for a booking (see GoogleCalendar::eventIdFor()).
     */
    public function calendarEventId(int $bookingId): string
    {
        return (new GoogleCalendar(new NullGoogleApi(), $this->googleConnections(), $this->outbox(), $this->clock, $this->config->appKey))->eventIdFor($bookingId);
    }

    private function calendarServices(): ?CalendarServices
    {
        $calendar = $this->googleCalendar();
        if ($calendar === null) {
            return null;
        }

        return new CalendarServices(
            $calendar,
            new CalendarLinks($this->pdo(), $this->clock),
            new GoogleDisconnectedHandler($this->pdo(), $this->bookingViews(), $this->mailer()),
        );
    }

    private function busyTime(): BusyTimeSource
    {
        $calendar = $this->googleCalendar();

        return $calendar === null ? new NoBusyTime() : new GoogleBusyTime($calendar, new GoogleBusyCache($this->pdo(), $this->clock));
    }

    private function googleApi(): ?GoogleApi
    {
        $google = $this->config->google;
        if ($google === null) {
            return null;
        }

        return $this->googleApi ??= new HttpGoogleApi($google->clientId, $google->clientSecret, $google->redirectUri, new Client());
    }

    private function mailer(): Mailer
    {
        return $this->mailer ??= new PhpMailerMailer($this->config->mail);
    }

    private function outbox(): Outbox
    {
        return $this->outbox ??= new Outbox($this->pdo(), $this->clock);
    }

    private function pdo(): PDO
    {
        return $this->db()->pdo();
    }
}
