<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use DateTimeZone;
use InvalidArgumentException;

/**
 * The person booking. Field-level validation with friendly messages belongs to the API layer;
 * these are the invariants the domain relies on.
 */
final class Customer
{
    private const MAX_NAME = 120;
    private const MAX_EMAIL = 254;
    private const PHONE_PATTERN = '/^\+?[0-9][0-9 ()-]{5,19}$/';

    public readonly string $name;
    public readonly string $email;
    public readonly ?string $phone;
    public readonly ?string $timezone;

    public function __construct(string $name, string $email, ?string $phone = null, ?string $timezone = null)
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new InvalidArgumentException(sprintf('Name is required and may be at most %d characters.', self::MAX_NAME));
        }

        $email = strtolower(trim($email));
        if (strlen($email) > self::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email address is required.');
        }

        $phone = $phone === null ? null : trim($phone);
        if ($phone === '') {
            $phone = null;
        }
        if ($phone !== null && preg_match(self::PHONE_PATTERN, $phone) !== 1) {
            throw new InvalidArgumentException('Phone number is not valid.');
        }

        if ($timezone !== null && !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Timezone is not valid.');
        }

        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
        $this->timezone = $timezone;
    }
}
