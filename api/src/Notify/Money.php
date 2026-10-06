<?php

declare(strict_types=1);

namespace ConsultDesk\Notify;

use NumberFormatter;

final class Money
{
    private const LOCALE = 'en_IN';

    /**
     * Human-readable amount, e.g. ₹1,499 or ₹1,49,999.50; zero is "Free".
     */
    public static function format(int $minor, string $currency): string
    {
        if ($minor === 0) {
            return 'Free';
        }

        $formatter = new NumberFormatter(self::LOCALE, NumberFormatter::CURRENCY);
        $digits = $minor % 100 === 0 ? 0 : 2;
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

        $formatted = $formatter->formatCurrency($minor / 100, $currency);

        return $formatted === false ? sprintf('%s %s', $currency, self::decimal($minor)) : $formatted;
    }

    /**
     * Plain decimal amount with two places, as payment links expect (149900 → "1499.00").
     */
    public static function decimal(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
