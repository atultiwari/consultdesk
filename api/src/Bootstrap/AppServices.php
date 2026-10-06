<?php

declare(strict_types=1);

namespace ConsultDesk\Bootstrap;

use ConsultDesk\Cron\CronRunner;
use ConsultDesk\Domain\Availability\NoBusyTime;
use ConsultDesk\Domain\Availability\SlotFinder;
use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Domain\Booking\PdoBookingRepository;
use ConsultDesk\Domain\Booking\PdoBookingViews;
use ConsultDesk\Domain\Booking\RandomRefGenerator;
use ConsultDesk\Domain\Catalog\PdoCatalog;
use ConsultDesk\Http\ClientIp;
use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Config;
use ConsultDesk\Infra\Crypto;
use ConsultDesk\Infra\Db;
use ConsultDesk\Infra\RateLimiter;
use ConsultDesk\Infra\SystemClock;
use ConsultDesk\Notify\Mail\BookingEmails;
use ConsultDesk\Notify\Mail\Mailer;
use ConsultDesk\Notify\Mail\PhpMailerMailer;
use ConsultDesk\Notify\NotificationHandlers;
use ConsultDesk\Notify\Outbox;
use ConsultDesk\Notify\OutboxBookingEvents;
use ConsultDesk\Notify\OutboxWorker;
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
        return new SlotFinder(new PdoBookingRepository($this->pdo()), new NoBusyTime(), $this->clock);
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
            $this->mailer ??= new PhpMailerMailer($this->config->mail),
            new BookingEmails(),
            $this->crypto(),
            $this->config->appUrl,
        );

        return new CronRunner($this->pdo(), $this->bookingService(), new OutboxWorker($this->outbox(), $handlers), $this->rateLimiter());
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
