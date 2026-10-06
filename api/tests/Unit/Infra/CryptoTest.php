<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Infra;

use ConsultDesk\Infra\Crypto;
use ConsultDesk\Infra\DecryptionFailed;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testRoundTripsAndUsesAFreshNonceEachTime(): void
    {
        $crypto = new Crypto(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $a = $crypto->encrypt('status-token');
        $b = $crypto->encrypt('status-token');

        self::assertNotSame($a, $b);
        self::assertStringNotContainsString('status-token', $a);
        self::assertSame('status-token', $crypto->decrypt($a));
        self::assertSame('status-token', $crypto->decrypt($b));
    }

    public function testRejectsTamperedOrForeignCiphertext(): void
    {
        $crypto = new Crypto(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $other = new Crypto(str_repeat('x', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $ciphertext = $crypto->encrypt('secret');

        foreach ([$other->encrypt('secret'), substr($ciphertext, 0, -4) . 'AAAA', 'not base64 !!', ''] as $bad) {
            try {
                $crypto->decrypt($bad);
                self::fail('Expected decryption to fail.');
            } catch (DecryptionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKeyMustBeExactly32Bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Crypto('too-short');
    }
}
