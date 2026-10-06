<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

/**
 * argon2id password hashing (docs/PLAN.md §10).
 */
final class Passwords
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 200;
    /** Verified against when the email is unknown, so both cases take the same time. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$Yi5OdFY2WmFBcHJBNDE1QQ$25POvGUjmKJgY1NKmo4+LmKPV4CNG+OES9vVJmonj54';

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /**
     * Always does the full hashing work, even for a missing account or an invited user who has no
     * password yet, so timing does not tell them apart.
     */
    public function verify(#[\SensitiveParameter] string $password, ?string $hash): bool
    {
        $usable = $hash !== null && str_starts_with($hash, '$');
        $ok = password_verify($password, $usable ? $hash : self::DUMMY_HASH);

        return $usable && $ok;
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    public static function acceptable(#[\SensitiveParameter] string $password): bool
    {
        $length = mb_strlen($password);

        return $length >= self::MIN_LENGTH && $length <= self::MAX_LENGTH;
    }
}
