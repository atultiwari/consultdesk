<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Booking;

use ConsultDesk\Domain\Booking\Customer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    public function testTrimsAndNormalisesFields(): void
    {
        $customer = new Customer('  Asha Placeholder ', ' Asha@Example.TEST ', ' +910000000000 ', 'Asia/Kolkata');

        self::assertSame('Asha Placeholder', $customer->name);
        self::assertSame('asha@example.test', $customer->email);
        self::assertSame('+910000000000', $customer->phone);
        self::assertSame('Asia/Kolkata', $customer->timezone);
    }

    public function testPhoneAndTimezoneAreOptional(): void
    {
        $customer = new Customer('Asha', 'asha@example.test', '  ');

        self::assertNull($customer->phone);
        self::assertNull($customer->timezone);
    }

    /**
     * @return iterable<string, array{string, string, ?string, ?string}>
     */
    public static function invalid(): iterable
    {
        yield 'blank name' => ['  ', 'asha@example.test', null, null];
        yield 'long name' => [str_repeat('a', 121), 'asha@example.test', null, null];
        yield 'bad email' => ['Asha', 'not-an-email', null, null];
        yield 'bad phone' => ['Asha', 'asha@example.test', 'call me', null];
        yield 'bad timezone' => ['Asha', 'asha@example.test', null, 'Nowhere/Land'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidCustomer(string $name, string $email, ?string $phone, ?string $timezone): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Customer($name, $email, $phone, $timezone);
    }
}
