<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Notify;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Domain\Booking\Customer;
use ConsultDesk\Domain\Booking\HeldBooking;
use ConsultDesk\Domain\Booking\HoldRequest;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Domain\Booking\PdoBookingRepository;
use ConsultDesk\Domain\Booking\PdoBookingViews;
use ConsultDesk\Domain\Booking\RandomRefGenerator;
use ConsultDesk\Infra\Crypto;
use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Notify\Mail\BookingEmails;
use ConsultDesk\Notify\NotificationHandlers;
use ConsultDesk\Notify\Outbox;
use ConsultDesk\Notify\OutboxBookingEvents;
use ConsultDesk\Notify\OutboxWorker;
use ConsultDesk\Tests\Integration\IntegrationTestCase;
use ConsultDesk\Tests\Integration\Support\Fixtures;
use ConsultDesk\Tests\Support\ArrayMailer;
use DateTimeImmutable;
use DateTimeZone;

final class BookingNotificationsTest extends IntegrationTestCase
{
    private const NOW = '2026-10-05T00:00Z';
    private const APP_URL = 'https://book.example.test';

    private int $providerId;
    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->providerId = Fixtures::provider($this->pdo, ['name' => 'Dr. Demo', 'notify_email' => 'provider@example.test', 'whatsapp' => '+910000000000']);
        $this->serviceId = Fixtures::service($this->pdo, $this->providerId, ['title' => 'Thesis guidance']);
        Fixtures::user($this->pdo, 'owner', email: 'owner@example.test');
        Fixtures::user($this->pdo, 'admin');
    }

    public function testUpiHoldEmailsTheCustomerAWorkingStatusLink(): void
    {
        $held = $this->bookings()->hold($this->request());
        $mailer = $this->drain();

        self::assertSame(['Complete your payment for ' . $held->ref], $mailer->subjects());
        $email = $mailer->sent[0];
        self::assertSame(['asha@example.test'], $email->to);
        self::assertSame('provider@example.test', $email->replyTo);
        self::assertStringContainsString(self::APP_URL . "/b/{$held->ref}?t={$held->publicToken}", $email->text);
        self::assertStringContainsString('placeholder@upi', $email->text);
    }

    public function testUtrSubmissionNotifiesCustomerAndStaffWithoutLeakingTheToken(): void
    {
        $held = $this->bookings()->hold($this->request());
        $this->bookings()->submitUtr($held->id, '412345678901');
        $mailer = $this->drain();

        // The held email is skipped: by the time cron runs the customer has already paid.
        self::assertSame(["Payment received, verifying: {$held->ref}", "Verify UPI payment for {$held->ref} (₹1,499)"], $mailer->subjects());
        $staff = $mailer->sent[1];
        self::assertSame(['provider@example.test', 'owner@example.test'], $staff->to, 'provider plus the owner, not admins');
        self::assertSame('asha@example.test', $staff->replyTo);
        self::assertStringNotContainsString($held->publicToken, $staff->text);
        self::assertStringContainsString('412345678901', $staff->text);
    }

    public function testConfirmationAndExpiryEmails(): void
    {
        $confirmed = $this->bookings()->hold($this->request());
        $this->bookings()->submitUtr($confirmed->id, '412345678901');
        $this->bookings()->confirm($confirmed->id, Actor::system());
        $lapsed = $this->bookings()->hold($this->request('15:00'));
        $this->drain();

        $this->bookings('2026-10-05T01:00Z')->expireStale();
        $mailer = $this->drain('2026-10-05T01:00Z');

        self::assertSame(["Booking expired: {$lapsed->ref}"], $mailer->subjects());
        self::assertStringContainsString('do not pay', $mailer->sent[0]->text);
    }

    public function testFreeApprovalRequestGoesToStaffAndCustomer(): void
    {
        $free = Fixtures::service($this->pdo, $this->providerId, ['price_minor' => 0, 'payment_methods' => '["free"]', 'requires_approval' => 1]);
        $held = $this->bookings()->hold($this->request(serviceId: $free, method: PaymentMethod::Free));
        $mailer = $this->drain();

        self::assertSame(["Request received: {$held->ref}", "Approve booking request {$held->ref}"], $mailer->subjects());
    }

    public function testStaffFallsBackToTheOwnerWhenTheProviderHasNoEmail(): void
    {
        $this->pdo->exec("UPDATE providers SET notify_email = NULL WHERE id = {$this->providerId}");
        $held = $this->bookings()->hold($this->request());
        $this->bookings()->submitUtr($held->id, '412345678901');
        $mailer = $this->drain();

        self::assertSame(['owner@example.test'], $mailer->sent[1]->to);
    }

    public function testAFailedSendIsRetriedWithoutResendingOtherEmails(): void
    {
        $held = $this->bookings()->hold($this->request());
        $this->bookings()->submitUtr($held->id, '412345678901');

        $flaky = new ArrayMailer(failNext: 1);
        $this->drain(mailer: $flaky);
        self::assertCount(1, $flaky->sent, 'one of the two emails failed');

        $this->drain('2026-10-05T00:02Z', $flaky);
        self::assertCount(2, $flaky->sent);
        self::assertNotSame($flaky->sent[0]->subject, $flaky->sent[1]->subject, 'no duplicates');
    }

    private function bookings(string $now = self::NOW): BookingService
    {
        $clock = new FrozenClock($now);

        return new BookingService(
            $this->database,
            new PdoBookingRepository($this->pdo),
            $clock,
            new RandomRefGenerator(),
            new OutboxBookingEvents(new Outbox($this->pdo, $clock)),
            self::crypto(),
        );
    }

    /**
     * Runs the worker until the queue is empty (events fan out into email jobs on the first pass).
     */
    private function drain(string $now = self::NOW, ?ArrayMailer $mailer = null): ArrayMailer
    {
        $mailer ??= new ArrayMailer();
        $outbox = new Outbox($this->pdo, new FrozenClock($now));
        $handlers = NotificationHandlers::build(new PdoBookingViews($this->pdo), $outbox, $mailer, new BookingEmails(), self::crypto(), self::APP_URL);
        $worker = new OutboxWorker($outbox, $handlers);
        for ($pass = 0; $pass < 5; $pass++) {
            $result = $worker->run(50);
            if ($result->succeeded + $result->failed === 0) {
                break;
            }
        }

        return $mailer;
    }

    private function request(string $istTime = '10:00', ?int $serviceId = null, PaymentMethod $method = PaymentMethod::Upi): HoldRequest
    {
        return new HoldRequest(
            providerId: $this->providerId,
            serviceId: $serviceId ?? $this->serviceId,
            start: new DateTimeImmutable("2026-10-07 {$istTime}", new DateTimeZone('Asia/Kolkata')),
            customer: new Customer('Asha Placeholder', 'asha@example.test', null, 'Asia/Kolkata'),
            paymentMethod: $method,
            answers: ['goal' => 'Thesis feedback'],
        );
    }

    private static function crypto(): Crypto
    {
        return new Crypto(str_repeat('t', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
