<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Admin;

use ConsultDesk\Admin\AdminBookings;
use ConsultDesk\Domain\Booking\BookingStatus as S;
use ConsultDesk\Domain\Booking\PaymentMethod as M;
use PHPUnit\Framework\TestCase;

final class AdminBookingsActionsTest extends TestCase
{
    public function testOffersTheActionsTheStatusAllows(): void
    {
        self::assertSame(['confirm', 'reject', 'cancel'], AdminBookings::actionsFor(S::AwaitingVerification, M::Upi));
        self::assertSame(['cancel'], AdminBookings::actionsFor(S::Held, M::Upi), 'nothing to verify before a UTR arrives');
        self::assertSame(['confirm', 'reject', 'cancel'], AdminBookings::actionsFor(S::Held, M::Free));
        self::assertSame(['complete', 'no-show', 'cancel'], AdminBookings::actionsFor(S::Confirmed, M::Upi));
        self::assertSame([], AdminBookings::actionsFor(S::Rejected, M::Upi));
    }

    public function testOnlyThePaymentGatewayConfirmsALinkPayment(): void
    {
        self::assertSame(['cancel'], AdminBookings::actionsFor(S::Held, M::RazorpayLink));
    }

    public function testALapsedHoldCanOnlyBeCancelled(): void
    {
        self::assertSame(['cancel'], AdminBookings::actionsFor(S::AwaitingVerification, M::Upi, holdLapsed: true));
    }
}
