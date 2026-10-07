<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use InvalidArgumentException;

/**
 * The first owner's details from .env (OWNER_EMAIL, OWNER_NAME, OWNER_PASSWORD). With a password,
 * the owner is created automatically on a fresh install; without one, the first-run form is
 * filled in with the email and name and asks only for a password.
 */
final class OwnerDefaults
{
    private function __construct(
        public readonly string $email,
        public readonly ?string $name,
        #[\SensitiveParameter]
        public readonly ?string $password,
    ) {}

    /**
     * @param array<string, string> $values
     */
    public static function fromValues(array $values): ?self
    {
        $email = strtolower(trim($values['OWNER_EMAIL'] ?? ''));
        $password = $values['OWNER_PASSWORD'] ?? '';
        if ($email === '') {
            if ($password !== '') {
                throw new InvalidArgumentException('OWNER_PASSWORD needs OWNER_EMAIL as well.');
            }

            return null;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('OWNER_EMAIL must be an email address.');
        }
        if ($password !== '' && !Passwords::acceptable($password)) {
            throw new InvalidArgumentException(sprintf('OWNER_PASSWORD must be %d–%d characters.', Passwords::MIN_LENGTH, Passwords::MAX_LENGTH));
        }
        $name = trim($values['OWNER_NAME'] ?? '');

        return new self($email, $name === '' ? null : mb_substr($name, 0, 120), $password === '' ? null : $password);
    }
}
