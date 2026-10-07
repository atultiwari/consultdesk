<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Infra\Settings;

/**
 * What booking codes start with, e.g. VRL in VRL-7F3K. Staff choose it in the admin panel; until
 * then it's BOOKING_PREFIX from .env, or CD. Changing it affects new bookings only.
 */
final class BookingRefPrefix
{
    public const FALLBACK = 'CD';
    private const KEY = 'booking';
    /** 2–6 capital letters or digits, starting with a letter. */
    private const PATTERN = '/^[A-Z][A-Z0-9]{1,5}$/';

    public function __construct(
        private readonly Settings $settings,
        private readonly string $default = self::FALLBACK,
    ) {}

    /**
     * " vrl- " → "VRL"; null when it can't be a prefix.
     */
    public static function normalise(string $value): ?string
    {
        $prefix = strtoupper(rtrim(trim($value), '-'));

        return preg_match(self::PATTERN, $prefix) === 1 ? $prefix : null;
    }

    public function current(): string
    {
        $stored = $this->settings->get(self::KEY)['prefix'] ?? null;

        return is_string($stored) ? (self::normalise($stored) ?? $this->default) : $this->default;
    }

    public function default(): string
    {
        return $this->default;
    }

    /**
     * @param string|null $prefix already normalised; null goes back to the default
     */
    public function save(?string $prefix): void
    {
        $this->settings->put(self::KEY, [...$this->settings->get(self::KEY), 'prefix' => $prefix]);
    }
}
