<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Payments;

use ConsultDesk\Payments\ManualUpi;
use ConsultDesk\Tests\Support\BookingViews;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ManualUpiTest extends TestCase
{
    public function testBuildsTheUpiDeepLinkAndWhatsAppLink(): void
    {
        $upi = ManualUpi::instructions(BookingViews::make());

        self::assertSame('placeholder@upi', $upi->vpa);
        self::assertSame('Demo Payee', $upi->payeeName);
        self::assertSame('₹1,499', $upi->amountDisplay);
        self::assertSame('upi://pay?pa=placeholder%40upi&pn=Demo%20Payee&am=1499.00&cu=INR&tn=CD-7F3K', $upi->uri);
        self::assertNotNull($upi->whatsappUrl);
        self::assertStringStartsWith('https://wa.me/910000000000?text=', $upi->whatsappUrl);
        self::assertStringContainsString(rawurlencode('CD-7F3K'), $upi->whatsappUrl);
    }

    public function testWhatsAppLinkIsOptional(): void
    {
        self::assertNull(ManualUpi::instructions(BookingViews::make(['providerWhatsapp' => null]))->whatsappUrl);
    }

    public function testNeedsAVpa(): void
    {
        $this->expectException(LogicException::class);
        ManualUpi::instructions(BookingViews::make(['upiVpa' => null]));
    }
}
