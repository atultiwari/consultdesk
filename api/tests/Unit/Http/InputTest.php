<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Http;

use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Http\Validation\ValidationFailed;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testReadsValidValuesAndTrimsStrings(): void
    {
        $input = new Input([
            'name' => '  Asha  ',
            'email' => 'Asha@Example.test',
            'method' => 'upi',
            'start' => '2026-10-07T04:30:00Z',
            'day' => '2026-10-07',
            'customer' => ['phone' => '+910000000000'],
            'answers' => ['goal' => 'x'],
        ]);

        self::assertSame('Asha', $input->string('name', max: 120));
        self::assertSame('Asha@Example.test', $input->email('email'));
        self::assertSame('upi', $input->oneOf('method', ['upi', 'free']));
        self::assertSame('2026-10-07T04:30:00+00:00', $input->dateTime('start')?->format(DATE_ATOM));
        self::assertSame('2026-10-07', $input->date('day'));
        self::assertSame('+910000000000', $input->nested('customer')->string('phone'));
        self::assertSame(['goal' => 'x'], $input->map('answers'));
        self::assertNull($input->string('missing', required: false));
        $input->assertValid();
    }

    public function testCollectsEveryFieldErrorBeforeFailing(): void
    {
        $input = new Input([
            'name' => '',
            'email' => 'nope',
            'method' => 'cash',
            'start' => '2026-10-07 04:30',
            'day' => '2026-02-30',
            'customer' => 'not an object',
            'long' => str_repeat('a', 11),
            'answers' => [1, 2],
        ]);

        $input->string('name');
        $input->email('email');
        $input->oneOf('method', ['upi', 'free']);
        $input->dateTime('start');
        $input->date('day');
        $input->nested('customer')->string('phone');
        $input->string('long', max: 10);
        $input->map('answers');

        try {
            $input->assertValid();
            self::fail('Expected validation to fail.');
        } catch (ValidationFailed $e) {
            self::assertSame(
                ['name', 'email', 'method', 'start', 'day', 'customer', 'customer.phone', 'long', 'answers'],
                array_keys($e->fields),
            );
            self::assertSame('validation_failed', $e->errorCode());
        }
    }

    public function testNestedErrorsArePrefixed(): void
    {
        $input = new Input(['customer' => ['email' => 'bad']]);
        $input->nested('customer')->email('email');

        $this->expectException(ValidationFailed::class);
        try {
            $input->assertValid();
        } finally {
            self::assertArrayHasKey('customer.email', $input->errors());
        }
    }

    public function testRejectsNonStringScalars(): void
    {
        $input = new Input(['name' => ['array'], 'n' => 5]);
        $input->string('name');
        $input->string('n');

        self::assertSame(['name', 'n'], array_keys($input->errors()));
    }

    public function testPhoneNumbersAreStoredWithTheirCountryCodeInOneForm(): void
    {
        $input = new Input([
            'spaced' => '+91 98765-43210',
            'zeros' => '0091 98765 43210',
            'plain' => '+14155550123',
            'local' => '09876543210',
            'short' => '+91 123',
        ]);

        self::assertSame('+919876543210', $input->phone('spaced'));
        self::assertSame('+919876543210', $input->phone('zeros'), '00 is the same as +');
        self::assertSame('+14155550123', $input->phone('plain'));
        self::assertNull($input->phone('local'), 'a number without its country code is ambiguous');
        self::assertNull($input->phone('short'));
        self::assertSame(['local', 'short'], array_keys($input->errors()));
    }
}
