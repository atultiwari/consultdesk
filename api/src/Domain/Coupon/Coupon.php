<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Coupon;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A discount code: a percentage or an amount off, for the whole site or one teacher, optionally
 * only some sessions, between optional dates.
 */
final class Coupon
{
    public const PERCENT = 'percent';
    public const AMOUNT = 'amount';
    /** Discounts are whole rupees, so customers never see amounts like ₹799.20. */
    private const ROUND_TO = 100;

    /**
     * @param list<int>|null $serviceIds
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly ?int $providerId,
        public readonly string $kind,
        public readonly int $value,
        public readonly ?array $serviceIds,
        public readonly ?DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly ?int $maxUses,
        public readonly bool $oncePerEmail,
        public readonly bool $active,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $services = is_string($row['service_ids'] ?? null) ? json_decode($row['service_ids'], true) : null;

        return new self(
            (int) $row['id'],
            (string) $row['code'],
            $row['provider_id'] === null ? null : (int) $row['provider_id'],
            (string) $row['kind'] === self::AMOUNT ? self::AMOUNT : self::PERCENT,
            (int) $row['value'],
            is_array($services) ? array_values(array_map('intval', $services)) : null,
            self::time($row['valid_from'] ?? null),
            self::time($row['valid_until'] ?? null),
            $row['max_uses'] === null ? null : (int) $row['max_uses'],
            (bool) $row['once_per_email'],
            (bool) $row['active'],
        );
    }

    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function appliesTo(int $providerId, int $serviceId): bool
    {
        return ($this->providerId === null || $this->providerId === $providerId)
            && ($this->serviceIds === null || $this->serviceIds === [] || in_array($serviceId, $this->serviceIds, true));
    }

    public function isLive(DateTimeImmutable $now): bool
    {
        return $this->active
            && ($this->validFrom === null || $now >= $this->validFrom)
            && ($this->validUntil === null || $now <= $this->validUntil);
    }

    public function discountFor(int $priceMinor): int
    {
        if ($this->kind === self::AMOUNT) {
            return min($this->value, $priceMinor);
        }
        if ($this->value >= 100) {
            return $priceMinor;
        }

        return min($priceMinor, intdiv(intdiv($priceMinor * $this->value, 100), self::ROUND_TO) * self::ROUND_TO);
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
    }
}
