<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use InvalidArgumentException;
use SodiumException;

/**
 * Authenticated symmetric encryption (libsodium secretbox) for secrets stored in the database.
 * Output is base64(nonce || ciphertext).
 */
final class Crypto
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $key,
    ) {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidArgumentException(sprintf('Encryption key must be %d bytes.', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        }
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * @throws DecryptionFailed when the value is malformed, tampered with or made with another key
     */
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new DecryptionFailed('Malformed ciphertext.');
        }

        try {
            $plaintext = sodium_crypto_secretbox_open(
                substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                $this->key,
            );
        } catch (SodiumException $e) {
            throw new DecryptionFailed('Malformed ciphertext.', 0, $e);
        }

        if ($plaintext === false) {
            throw new DecryptionFailed('Ciphertext failed authentication.');
        }

        return $plaintext;
    }
}
