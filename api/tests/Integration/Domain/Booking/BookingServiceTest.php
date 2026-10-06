<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Domain\Booking;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\BookingNotFound;
use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\Customer;
use ConsultDesk\Domain\Booking\DailyLimitReached;
use ConsultDesk\Domain\Booking\DuplicateUtr;
use ConsultDesk\Domain\Booking\HeldBooking;
use ConsultDesk\Domain\Booking\HoldExpired;
use ConsultDesk\Domain\Booking\HoldRequest;
use ConsultDesk\Domain\Booking\InvalidTransition;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Domain\Booking\PaymentMethodNotAllowed;
use ConsultDesk\Domain\Booking\PdoBookingRepository;
use ConsultDesk\Domain\Booking\RandomRefGenerator;
use ConsultDesk\Domain\Booking\RefGenerator;
use ConsultDesk\Domain\Booking\ServiceNotBookable;
use ConsultDesk\Domain\Booking\SlotUnavailable;
use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Tests\Integration\IntegrationTestCase;
use ConsultDesk\Tests\Integration\Support\Fixtures;
use ConsultDesk\Tests\Integration\Support\SequenceRefs;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * "Now" is Monday 2026-10-05 00:00 UTC. With the default 24 h notice, the first bookable
 * instant is Tuesday 00:00 UTC; tests book Wednesday 7 October, in IST unless noted.
 */
final class BookingServiceTest extends IntegrationTestCase
{
    private const NOW = '2026-10-05T00:00Z';

    private int $providerId;
    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->providerId = Fixtures::provider($this->pdo);
        $this->serviceId = Fixtures::service($this->pdo, $this->providerId);
    }

    public function testHoldCreatesAHeldBookingWithAHashedTokenAndAuditEntry(): void
    {
        $held = $this->service()->hold($this->request('10:00', answers: ['goal' => 'Thesis feedback']));

        self::assertMatchesRegularExpression('/^CD-[2-9A-Z]{4}$/', $held->ref);
        self::assertSame('2026-10-05T01:00:00+00:00', $held->holdExpiresAt->format(DATE_ATOM));

        $row = $this->booking($held->id);
        self::assertSame('held', $row['status']);
        self::assertSame('2026-10-07 04:30:00', $row['start_at']);
        self::assertSame('2026-10-07 05:30:00', $row['end_at']);
        self::assertSame(149900, (int) $row['amount_minor']);
        self::assertSame('INR', $row['currency']);
        self::assertSame('upi', $row['payment_method']);
        self::assertSame(hash('sha256', $held->publicToken), $row['public_token_hash']);
        self::assertSame(['goal' => 'Thesis feedback'], json_decode((string) $row['answers'], true));
        self::assertSame('Asha Placeholder', $row['customer_name']);
        self::assertSame(['booking.held'], $this->auditActions($held->id));
    }

    public function testRejectsASecondHoldOnTheSameSlot(): void
    {
        $this->service()->hold($this->request('10:00'));

        $this->expectException(SlotUnavailable::class);
        $this->service()->hold($this->request('10:00'));
    }

    public function testRequiresTheLargerBufferAsAGapBetweenBookings(): void
    {
        // Default buffers are 10/10, so the gap is 10 minutes.
        $this->service()->hold($this->request('10:00'));

        $this->assertHoldFails(SlotUnavailable::class, '11:05');
        $this->assertHoldFails(SlotUnavailable::class, '08:55');
        self::assertInstanceOf(HeldBooking::class, $this->service()->hold($this->request('11:10')));
        self::assertInstanceOf(HeldBooking::class, $this->service()->hold($this->request('08:50')));
    }

    public function testExpiredAndCancelledBookingsFreeTheSlot(): void
    {
        $this->service()->hold($this->request('10:00'));
        $later = $this->service('2026-10-05T01:00Z'); // the 60-minute UPI hold has just lapsed

        $second = $later->hold($this->request('10:00'));
        $later->cancel($second->id, Actor::system());

        self::assertInstanceOf(HeldBooking::class, $later->hold($this->request('10:00')));
    }

    public function testDailyLimitIsCountedInTheProvidersTimezone(): void
    {
        $this->pdo->exec("UPDATE providers SET max_per_day = 1 WHERE id = {$this->providerId}");
        $this->service()->hold($this->request('10:00'));

        $this->assertHoldFails(DailyLimitReached::class, '15:00');
        // 23:00 IST on the 6th is 17:30 UTC on the 6th: a different local day from the first booking.
        self::assertInstanceOf(HeldBooking::class, $this->service()->hold($this->request('23:00', '2026-10-06')));
    }

    public function testRejectsSlotsInsideTheNoticeOrBeyondTheHorizonOrInThePast(): void
    {
        $this->assertHoldFails(SlotUnavailable::class, '05:00', '2026-10-05'); // 23:30 UTC on the 4th: past
        $this->assertHoldFails(SlotUnavailable::class, '05:00', '2026-10-06'); // 23:30 UTC on the 5th: inside 24 h
        $this->assertHoldFails(SlotUnavailable::class, '10:00', '2026-11-05'); // beyond 30 days
        self::assertInstanceOf(HeldBooking::class, $this->service()->hold($this->request('05:30', '2026-10-06')));
    }

    public function testRejectsServicesThatCannotBeBooked(): void
    {
        $otherProvider = Fixtures::provider($this->pdo);
        $otherService = Fixtures::service($this->pdo, $otherProvider);
        $inactiveService = Fixtures::service($this->pdo, $this->providerId, ['active' => 0]);
        $inactiveProvider = Fixtures::provider($this->pdo, ['active' => 0]);
        $serviceOfInactive = Fixtures::service($this->pdo, $inactiveProvider);

        foreach ([
            [$this->providerId, $otherService],
            [$this->providerId, $inactiveService],
            [$inactiveProvider, $serviceOfInactive],
            [999_999, $this->serviceId],
            [$this->providerId, 999_999],
        ] as [$providerId, $serviceId]) {
            try {
                $this->service()->hold($this->request('10:00', providerId: $providerId, serviceId: $serviceId));
                self::fail("Expected provider {$providerId} / service {$serviceId} to be unbookable.");
            } catch (ServiceNotBookable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPaymentMethodMustSuitTheService(): void
    {
        $free = Fixtures::service($this->pdo, $this->providerId, ['price_minor' => 0, 'payment_methods' => '["free"]']);
        $upiOnly = Fixtures::service($this->pdo, $this->providerId, ['payment_methods' => '["upi"]']);

        $this->assertHoldFails(PaymentMethodNotAllowed::class, '10:00', method: PaymentMethod::Free);
        $this->assertHoldFails(PaymentMethodNotAllowed::class, '10:00', serviceId: $upiOnly, method: PaymentMethod::RazorpayLink);
        $this->assertHoldFails(PaymentMethodNotAllowed::class, '10:00', serviceId: $free, method: PaymentMethod::Upi);

        $held = $this->service()->hold($this->request('10:00', serviceId: $free, method: PaymentMethod::Free));
        self::assertSame(0, (int) $this->booking($held->id)['amount_minor']);
        self::assertSame('2026-10-06T00:00:00+00:00', $held->holdExpiresAt->format(DATE_ATOM), 'free holds last 24 h');
    }

    public function testRetriesWhenAGeneratedRefIsAlreadyTaken(): void
    {
        $first = $this->service(refs: new SequenceRefs(['CD-AAAA']))->hold($this->request('10:00'));
        $second = $this->service(refs: new SequenceRefs(['CD-AAAA', 'CD-BBBB']))->hold($this->request('14:00'));

        self::assertSame('CD-AAAA', $first->ref);
        self::assertSame('CD-BBBB', $second->ref);
    }

    public function testGivesUpAfterRepeatedRefCollisions(): void
    {
        $this->service(refs: new SequenceRefs(['CD-AAAA']))->hold($this->request('10:00'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('booking reference');
        $this->service(refs: new SequenceRefs(['CD-AAAA']))->hold($this->request('14:00'));
    }

    public function testSubmittingAUtrMovesToAwaitingVerificationAndExtendsTheHold(): void
    {
        $held = $this->service()->hold($this->request('10:00'));

        $this->service('2026-10-05T00:30Z')->submitUtr($held->id, '4123 4567 8901');

        $row = $this->booking($held->id);
        self::assertSame('awaiting_verification', $row['status']);
        self::assertSame('412345678901', $row['utr']);
        self::assertSame('2026-10-06 00:30:00', $row['hold_expires_at']);
        self::assertSame(['booking.held', 'booking.utr_submitted'], $this->auditActions($held->id));
    }

    public function testAUtrCanOnlyBeUsedOnce(): void
    {
        $first = $this->service()->hold($this->request('10:00'));
        $second = $this->service()->hold($this->request('14:00'));
        $this->service()->submitUtr($first->id, '412345678901');

        $this->expectException(DuplicateUtr::class);
        $this->service()->submitUtr($second->id, '412345678901');
    }

    public function testUtrIsOnlyForLiveUpiHolds(): void
    {
        $link = $this->service()->hold($this->request('10:00', method: PaymentMethod::RazorpayLink));
        $upi = $this->service()->hold($this->request('14:00'));

        $this->assertThrows(PaymentMethodNotAllowed::class, fn() => $this->service()->submitUtr($link->id, '412345678901'));
        $this->assertThrows(HoldExpired::class, fn() => $this->service('2026-10-05T01:00Z')->submitUtr($upi->id, '412345678901'));
        $this->assertThrows(BookingNotFound::class, fn() => $this->service()->submitUtr(999_999, '412345678901'));
    }

    public function testConfirmingAVerifiedUpiBookingRecordsWhoConfirmedIt(): void
    {
        $userId = Fixtures::user($this->pdo);
        $held = $this->service()->hold($this->request('10:00'));
        $this->service()->submitUtr($held->id, '412345678901');

        $this->service('2026-10-05T02:00Z')->confirm($held->id, Actor::user($userId));

        $row = $this->booking($held->id);
        self::assertSame('confirmed', $row['status']);
        self::assertSame($userId, (int) $row['confirmed_by']);
        self::assertSame('2026-10-05 02:00:00', $row['confirmed_at']);
        self::assertNull($row['hold_expires_at']);
        self::assertSame('2026-10-05 02:00:00', $row['status_changed_at']);
    }

    public function testUpiCannotBeConfirmedBeforeAUtrIsSubmitted(): void
    {
        $held = $this->service()->hold($this->request('10:00'));

        $this->expectException(InvalidTransition::class);
        $this->service()->confirm($held->id, Actor::system());
    }

    public function testPaymentLinkBookingIsConfirmedByTheWebhookWhileTheHoldIsLive(): void
    {
        $live = $this->service()->hold($this->request('10:00', method: PaymentMethod::RazorpayLink));
        $lapsed = $this->service()->hold($this->request('14:00', method: PaymentMethod::RazorpayLink));

        $this->service('2026-10-05T00:19Z')->confirm($live->id, Actor::system());
        self::assertSame('confirmed', $this->booking($live->id)['status']);
        self::assertNull($this->booking($live->id)['confirmed_by']);

        $this->expectException(HoldExpired::class);
        $this->service('2026-10-05T00:20Z')->confirm($lapsed->id, Actor::system());
    }

    public function testRejectCancelCompleteAndNoShow(): void
    {
        $free = Fixtures::service($this->pdo, $this->providerId, ['price_minor' => 0, 'payment_methods' => '["free"]', 'requires_approval' => 1]);
        $service = $this->service();
        $toReject = $service->hold($this->request('09:00', serviceId: $free, method: PaymentMethod::Free));
        $toComplete = $service->hold($this->request('11:00', serviceId: $free, method: PaymentMethod::Free));
        $toNoShow = $service->hold($this->request('13:00', '2026-10-08', serviceId: $free, method: PaymentMethod::Free));
        $toCancel = $service->hold($this->request('15:00', '2026-10-08', serviceId: $free, method: PaymentMethod::Free));

        $service->reject($toReject->id, Actor::system());
        foreach ([$toComplete, $toNoShow, $toCancel] as $booking) {
            $service->confirm($booking->id, Actor::system());
        }
        $service->complete($toComplete->id, Actor::system());
        $service->markNoShow($toNoShow->id, Actor::system());
        $service->cancel($toCancel->id, Actor::customer());

        self::assertSame('rejected', $this->booking($toReject->id)['status']);
        self::assertSame('completed', $this->booking($toComplete->id)['status']);
        self::assertSame('no_show', $this->booking($toNoShow->id)['status']);
        self::assertSame('cancelled', $this->booking($toCancel->id)['status']);
        self::assertSame(['booking.held', 'booking.confirmed', 'booking.cancelled'], $this->auditActions($toCancel->id));

        $this->expectException(InvalidTransition::class);
        $service->confirm($toReject->id, Actor::system());
    }

    public function testExpireStaleExpiresOnlyLapsedPendingBookings(): void
    {
        $service = $this->service();
        $lapsedHold = $service->hold($this->request('09:00'));
        $lapsedAwaiting = $service->hold($this->request('11:00'));
        $service->submitUtr($lapsedAwaiting->id, '412345678901'); // verification window ends 2026-10-06 00:00
        $lapsedLink = $service->hold($this->request('13:00', method: PaymentMethod::RazorpayLink));
        $this->service('2026-10-05T00:10Z')->hold($this->request('15:00', '2026-10-08', method: PaymentMethod::RazorpayLink));

        $count = $this->service('2026-10-06T00:00Z')->expireStale();

        self::assertSame(4, $count);
        self::assertSame(BookingStatus::Expired->value, $this->booking($lapsedHold->id)['status']);
        self::assertSame(BookingStatus::Expired->value, $this->booking($lapsedAwaiting->id)['status']);
        self::assertSame(['booking.held', 'booking.expired'], $this->auditActions($lapsedLink->id));
        self::assertSame(0, $this->service('2026-10-06T00:00Z')->expireStale(), 'nothing left to expire');

        $fresh = $this->service('2026-10-06T00:00Z')->hold($this->request('17:00', '2026-10-08'));
        self::assertSame(0, $this->service('2026-10-06T00:59Z')->expireStale());
        self::assertSame('held', $this->booking($fresh->id)['status']);
    }

    private function service(string $now = self::NOW, ?RefGenerator $refs = null): BookingService
    {
        return new BookingService(
            $this->database,
            new PdoBookingRepository($this->pdo),
            new FrozenClock($now),
            $refs ?? new RandomRefGenerator(),
        );
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function request(
        string $istTime,
        string $date = '2026-10-07',
        ?int $providerId = null,
        ?int $serviceId = null,
        PaymentMethod $method = PaymentMethod::Upi,
        array $answers = [],
    ): HoldRequest {
        return new HoldRequest(
            providerId: $providerId ?? $this->providerId,
            serviceId: $serviceId ?? $this->serviceId,
            start: new DateTimeImmutable("{$date} {$istTime}", new DateTimeZone('Asia/Kolkata')),
            customer: new Customer('Asha Placeholder', 'asha@example.test', '+910000000000', 'Asia/Kolkata'),
            paymentMethod: $method,
            answers: $answers,
        );
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    private function assertHoldFails(
        string $exception,
        string $istTime,
        string $date = '2026-10-07',
        ?int $serviceId = null,
        PaymentMethod $method = PaymentMethod::Upi,
    ): void {
        $this->assertThrows($exception, fn() => $this->service()->hold($this->request($istTime, $date, serviceId: $serviceId, method: $method)));
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    private function assertThrows(string $exception, callable $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);

            return;
        }
        self::fail("Expected {$exception}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function auditActions(int $bookingId): array
    {
        return self::column(
            $this->pdo,
            "SELECT action FROM audit_log WHERE entity_type = 'booking' AND entity_id = :id ORDER BY id",
            ['id' => $bookingId],
        );
    }
}
