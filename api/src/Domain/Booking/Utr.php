<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

/**
 * A UPI transaction reference (UTR / RRN): 12 digits.
 */
final class Utr
{
    public readonly string $value;

    public function __construct(string $input)
    {
        $digits = preg_replace('/\s+/', '', $input) ?? '';
        if (preg_match('/^\d{12}$/', $digits) !== 1 || $digits === str_repeat('0', 12)) {
            throw new InvalidUtr();
        }
        $this->value = $digits;
    }
}
