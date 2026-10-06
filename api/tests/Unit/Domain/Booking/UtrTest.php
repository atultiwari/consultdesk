<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Booking;

use ConsultDesk\Domain\Booking\InvalidUtr;
use ConsultDesk\Domain\Booking\Utr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UtrTest extends TestCase
{
    public function testAcceptsTwelveDigitsAndStripsSpaces(): void
    {
        self::assertSame('412345678901', (new Utr(' 4123 4567 8901 '))->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'too short' => ['41234567890'];
        yield 'too long' => ['4123456789012'];
        yield 'letters' => ['41234567890A'];
        yield 'empty' => [''];
        yield 'all zeros' => ['000000000000'];
    }

    #[DataProvider('invalid')]
    public function testRejectsMalformedUtr(string $input): void
    {
        $this->expectException(InvalidUtr::class);
        new Utr($input);
    }
}
