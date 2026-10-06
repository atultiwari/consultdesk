<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Availability\Interval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Read model of one booking with its provider and service, for emails and the status page.
 */
final class BookingView
{
    /**
     * @param array<string, mixed> $answers
     */
    public function __construct(
        public readonly int $id,
        public readonly string $ref,
        public readonly BookingStatus $status,
        public readonly PaymentMethod $paymentMethod,
        public readonly Interval $slot,
        public readonly string $customerName,
        public readonly string $customerEmail,
        public readonly ?string $customerPhone,
        public readonly ?string $customerTimezone,
        public readonly array $answers,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $utr,
        public readonly ?DateTimeImmutable $holdExpiresAt,
        public readonly ?string $publicTokenEnc,
        public readonly string $publicTokenHash,
        public readonly int $providerId,
        public readonly string $providerSlug,
        public readonly string $providerName,
        public readonly string $providerTimezone,
        public readonly ?string $providerWhatsapp,
        public readonly ?string $providerNotifyEmail,
        public readonly ?string $upiVpa,
        public readonly ?string $upiPayeeName,
        public readonly int $serviceId,
        public readonly string $serviceTitle,
        public readonly bool $requiresApproval,
        public readonly ?string $meetUrl,
        public readonly ?string $gcalEventId = null,
        public readonly ?string $gcalCalendarId = null,
        public readonly bool $calendarConnected = false,
    ) {}

    /**
     * Constant-time check of a status-page token against the stored hash.
     */
    public function tokenMatches(string $token): bool
    {
        return $token !== '' && hash_equals($this->publicTokenHash, RandomRefGenerator::hashToken($token));
    }

    /**
     * A pending booking whose hold has run out, even if cron has not marked it expired yet.
     */
    public function holdLapsed(DateTimeImmutable $now): bool
    {
        return in_array($this->status, [BookingStatus::Held, BookingStatus::AwaitingVerification], true)
            && $this->holdExpiresAt !== null
            && $this->holdExpiresAt <= $now;
    }

    /**
     * A copy whose display timezone is the provider's, for staff-facing messages.
     */
    public function asSeenByStaff(): self
    {
        $args = get_object_vars($this);
        $args['customerTimezone'] = $this->providerTimezone;

        return new self(...$args);
    }

    /**
     * The customer's timezone when known, otherwise the provider's.
     */
    public function displayTimezone(): DateTimeZone
    {
        return new DateTimeZone($this->customerTimezone ?? $this->providerTimezone);
    }
}
