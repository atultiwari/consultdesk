<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Booking;

use ConsultDesk\Domain\Booking\BookingStatus as S;
use ConsultDesk\Domain\Booking\InvalidTransition;
use ConsultDesk\Domain\Booking\PaymentMethod as M;
use ConsultDesk\Domain\Booking\StatusMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusMachineTest extends TestCase
{
    /**
     * Every allowed transition from docs/PLAN.md §5, per payment method.
     *
     * @return iterable<string, array{S, S, M}>
     */
    public static function allowed(): iterable
    {
        yield 'upi: utr submitted' => [S::Held, S::AwaitingVerification, M::Upi];
        yield 'upi: verified' => [S::AwaitingVerification, S::Confirmed, M::Upi];
        yield 'upi: rejected' => [S::AwaitingVerification, S::Rejected, M::Upi];
        yield 'upi: verification window lapsed' => [S::AwaitingVerification, S::Expired, M::Upi];
        yield 'upi: hold lapsed' => [S::Held, S::Expired, M::Upi];
        yield 'razorpay: webhook paid' => [S::Held, S::Confirmed, M::RazorpayLink];
        yield 'razorpay: link expired' => [S::Held, S::Expired, M::RazorpayLink];
        yield 'free: approved' => [S::Held, S::Confirmed, M::Free];
        yield 'free: rejected' => [S::Held, S::Rejected, M::Free];
        yield 'free: approval lapsed' => [S::Held, S::Expired, M::Free];
        yield 'cancel while held' => [S::Held, S::Cancelled, M::Upi];
        yield 'cancel while awaiting' => [S::AwaitingVerification, S::Cancelled, M::Upi];
        yield 'cancel confirmed' => [S::Confirmed, S::Cancelled, M::RazorpayLink];
        yield 'completed' => [S::Confirmed, S::Completed, M::Free];
        yield 'no show' => [S::Confirmed, S::NoShow, M::Upi];
        yield 'rescheduled' => [S::Confirmed, S::Rescheduled, M::Upi];
    }

    /**
     * @return iterable<string, array{S, S, M}>
     */
    public static function forbidden(): iterable
    {
        yield 'upi cannot skip verification' => [S::Held, S::Confirmed, M::Upi];
        yield 'upi cannot be rejected before a utr' => [S::Held, S::Rejected, M::Upi];
        yield 'only upi awaits verification' => [S::Held, S::AwaitingVerification, M::RazorpayLink];
        yield 'paid links are not rejected' => [S::Held, S::Rejected, M::RazorpayLink];
        yield 'free never awaits verification' => [S::Held, S::AwaitingVerification, M::Free];
        yield 'confirmed cannot go back' => [S::Confirmed, S::Held, M::Upi];
        yield 'held cannot complete' => [S::Held, S::Completed, M::Free];
        yield 'same status' => [S::Confirmed, S::Confirmed, M::Upi];
        yield 'expired is terminal' => [S::Expired, S::Confirmed, M::RazorpayLink];
        yield 'rejected is terminal' => [S::Rejected, S::Confirmed, M::Upi];
        yield 'cancelled is terminal' => [S::Cancelled, S::Confirmed, M::Upi];
        yield 'completed is terminal' => [S::Completed, S::NoShow, M::Upi];
    }

    #[DataProvider('allowed')]
    public function testAllowsTransition(S $from, S $to, M $method): void
    {
        self::assertTrue(StatusMachine::canTransition($from, $to, $method));
        StatusMachine::assertTransition($from, $to, $method);
    }

    #[DataProvider('forbidden')]
    public function testForbidsTransition(S $from, S $to, M $method): void
    {
        self::assertFalse(StatusMachine::canTransition($from, $to, $method));

        $this->expectException(InvalidTransition::class);
        $this->expectExceptionMessage(sprintf('%s to %s', $from->value, $to->value));
        StatusMachine::assertTransition($from, $to, $method);
    }

    public function testActiveStatusesAreTheOnesThatBlockASlot(): void
    {
        $active = array_values(array_filter(S::cases(), static fn(S $s): bool => $s->blocksSlot()));

        self::assertSame([S::Held, S::AwaitingVerification, S::Confirmed], $active);
    }
}
