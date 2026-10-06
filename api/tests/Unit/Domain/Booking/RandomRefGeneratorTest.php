<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Booking;

use ConsultDesk\Domain\Booking\RandomRefGenerator;
use PHPUnit\Framework\TestCase;

final class RandomRefGeneratorTest extends TestCase
{
    public function testRefsUseThePrefixAndAnUnambiguousAlphabet(): void
    {
        $generator = new RandomRefGenerator();
        $refs = array_map(static fn(): string => $generator->next(), range(1, 500));

        foreach ($refs as $ref) {
            self::assertMatchesRegularExpression('/^CD-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/', $ref);
        }
        self::assertGreaterThan(450, count(array_unique($refs)), 'refs should rarely repeat');
    }

    public function testTokensAreUrlSafeAndHashToSha256Hex(): void
    {
        $generator = new RandomRefGenerator();
        $token = $generator->token();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $token);
        self::assertNotSame($token, $generator->token());
        self::assertSame(hash('sha256', $token), RandomRefGenerator::hashToken($token));
    }
}
