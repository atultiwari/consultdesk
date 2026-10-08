<?php

declare(strict_types=1);

namespace ConsultDesk\Updates;

/**
 * The public half of the key that signs release zips (Ed25519). The private half exists only as the
 * RELEASE_SIGNING_KEY secret of the GitHub repository (and the maintainer's backup). An update is
 * installed only if its zip carries a valid signature from this key.
 */
final class ReleaseKey
{
    public const PUBLIC_KEY = 'Lp2cMYxnU2jjQLltsfcNtZ7DEwEPcw4Wh3+0ltGt4z4=';

    public static function verify(string $file, string $signatureBase64, string $publicKeyBase64 = self::PUBLIC_KEY): bool
    {
        $signature = base64_decode(trim($signatureBase64), true);
        $key = base64_decode($publicKeyBase64, true);
        $contents = is_file($file) ? file_get_contents($file) : false;
        if ($signature === false || $key === false || $contents === false
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $contents, $key);
    }
}
