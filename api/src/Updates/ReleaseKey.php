<?php

declare(strict_types=1);

namespace ConsultDesk\Updates;

/**
 * The public halves of the keys that sign releases (Ed25519). The private key exists only as the
 * RELEASE_SIGNING_KEY secret of the GitHub repository's "release-signing" environment (and the
 * maintainer's offline backup).
 *
 * A signature covers a small manifest, not just the bytes: the version and the zip's SHA-256. So a
 * signed zip can't be relabelled as another version. To rotate keys, a release signed with the old
 * key adds the new public key here; later releases are signed with the new one (docs/RELEASING.md).
 */
final class ReleaseKey
{
    /** @var list<string> */
    public const PUBLIC_KEYS = ['Lp2cMYxnU2jjQLltsfcNtZ7DEwEPcw4Wh3+0ltGt4z4='];

    public static function manifest(string $version, string $zipSha256): string
    {
        return "consultdesk-release\n" . $version . "\n" . $zipSha256;
    }

    /**
     * @param list<string> $publicKeys base64
     */
    public static function verify(string $file, string $version, string $signatureBase64, array $publicKeys = self::PUBLIC_KEYS): bool
    {
        $signature = base64_decode(trim($signatureBase64), true);
        $sha256 = is_file($file) ? hash_file('sha256', $file) : false;
        if ($signature === false || $sha256 === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        $manifest = self::manifest($version, $sha256);
        foreach ($publicKeys as $encoded) {
            $key = base64_decode($encoded, true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && sodium_crypto_sign_verify_detached($signature, $manifest, $key)) {
                return true;
            }
        }

        return false;
    }
}
