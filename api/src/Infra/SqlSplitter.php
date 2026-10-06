<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

/**
 * Splits a migration file into single statements. Understands quoted strings, backtick identifiers
 * and `--`, `#` and block comments, so a semicolon inside any of them does not end a statement.
 */
final class SqlSplitter
{
    /**
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::closingQuote($sql, $i, $char);
                $current .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif (($char === '-' && $next === '-') || $char === '#') {
                $i = self::lineEnd($sql, $i);
            } elseif ($char === '/' && $next === '*') {
                $close = strpos($sql, '*/', $i + 2);
                $i = $close === false ? $length : $close + 1;
            } elseif ($char === ';') {
                $statements[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $statements[] = $current;

        return array_values(array_filter(array_map('trim', $statements), static fn(string $s): bool => $s !== ''));
    }

    /**
     * Index of the quote that closes the one at $start; handles doubled quotes and backslash escapes.
     */
    private static function closingQuote(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        for ($i = $start + 1; $i < $length; $i++) {
            if ($sql[$i] === '\\' && $quote !== '`') {
                $i++;
            } elseif ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') !== $quote) {
                    return $i;
                }
                $i++;
            }
        }

        return $length - 1;
    }

    private static function lineEnd(string $sql, int $start): int
    {
        $newline = strpos($sql, "\n", $start);

        return $newline === false ? strlen($sql) : $newline;
    }
}
