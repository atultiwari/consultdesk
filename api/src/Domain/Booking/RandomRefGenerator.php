<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

final class RandomRefGenerator implements RefGenerator
{
    private const PREFIX = 'CD-';
    private const LENGTH = 4;
    /** Crockford-style: no 0/O, 1/I/L, so refs survive being read aloud or copied by hand. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const TOKEN_BYTES = 24;

    public function next(): string
    {
        $ref = self::PREFIX;
        $last = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $ref .= self::ALPHABET[random_int(0, $last)];
        }

        return $ref;
    }

    public function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
