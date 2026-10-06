<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Availability\Interval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoBookingViews implements BookingViewRepository
{
    private const SELECT = 'SELECT b.id, b.ref, b.status, b.payment_method, b.start_at, b.end_at,
            b.customer_name, b.customer_email, b.customer_phone, b.customer_timezone, b.answers,
            b.amount_minor, b.currency, b.utr, b.hold_expires_at, b.public_token_enc, b.public_token_hash, b.meet_url,
            p.id AS provider_id, p.slug AS provider_slug, p.name AS provider_name, p.timezone AS provider_timezone,
            p.whatsapp, p.notify_email, p.upi_vpa, p.upi_payee_name,
            s.id AS service_id, s.title AS service_title, s.requires_approval,
            b.gcal_event_id, b.gcal_calendar_id, (o.status = \'active\') AS calendar_connected, b.gateway_ref, b.gateway_url
        FROM bookings b
        JOIN providers p ON p.id = b.provider_id
        JOIN services s ON s.id = b.service_id
        LEFT JOIN oauth_tokens o ON o.provider_id = b.provider_id AND o.oauth_provider = \'google\'';

    public function __construct(private readonly PDO $pdo) {}

    public function findById(int $bookingId): ?BookingView
    {
        return $this->findOne(self::SELECT . ' WHERE b.id = :value', $bookingId);
    }

    public function findByRef(string $ref): ?BookingView
    {
        return $this->findOne(self::SELECT . ' WHERE b.ref = :value', $ref);
    }

    public function staffEmails(BookingView $booking): array
    {
        return $this->staffEmailsFor($booking->providerNotifyEmail);
    }

    public function staffEmailsForProvider(int $providerId): array
    {
        $statement = $this->pdo->prepare('SELECT notify_email FROM providers WHERE id = :id');
        $statement->execute(['id' => $providerId]);
        $notify = $statement->fetchColumn();

        return $this->staffEmailsFor(is_string($notify) ? $notify : null);
    }

    /**
     * @return list<string>
     */
    private function staffEmailsFor(?string $notifyEmail): array
    {
        $statement = $this->pdo->prepare("SELECT email FROM users WHERE role = 'owner' ORDER BY id");
        $statement->execute();

        $emails = [];
        foreach ([$notifyEmail, ...$statement->fetchAll(PDO::FETCH_COLUMN)] as $email) {
            if (is_string($email) && $email !== '') {
                $emails[strtolower($email)] ??= $email;
            }
        }

        return array_values($emails);
    }

    private function findOne(string $sql, int|string $value): ?BookingView
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['value' => $value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function hydrate(array $r): BookingView
    {
        $answers = json_decode((string) $r['answers'], true, 16, JSON_THROW_ON_ERROR);

        return new BookingView(
            id: (int) $r['id'],
            ref: (string) $r['ref'],
            status: BookingStatus::from((string) $r['status']),
            paymentMethod: PaymentMethod::from((string) $r['payment_method']),
            slot: new Interval(self::utc((string) $r['start_at']), self::utc((string) $r['end_at'])),
            customerName: (string) $r['customer_name'],
            customerEmail: (string) $r['customer_email'],
            customerPhone: self::nullable($r['customer_phone']),
            customerTimezone: self::nullable($r['customer_timezone']),
            answers: is_array($answers) ? $answers : [],
            amountMinor: (int) $r['amount_minor'],
            currency: (string) $r['currency'],
            utr: self::nullable($r['utr']),
            holdExpiresAt: $r['hold_expires_at'] === null ? null : self::utc((string) $r['hold_expires_at']),
            publicTokenEnc: self::nullable($r['public_token_enc']),
            publicTokenHash: (string) $r['public_token_hash'],
            providerId: (int) $r['provider_id'],
            providerSlug: (string) $r['provider_slug'],
            providerName: (string) $r['provider_name'],
            providerTimezone: (string) $r['provider_timezone'],
            providerWhatsapp: self::nullable($r['whatsapp']),
            providerNotifyEmail: self::nullable($r['notify_email']),
            upiVpa: self::nullable($r['upi_vpa']),
            upiPayeeName: self::nullable($r['upi_payee_name']),
            serviceId: (int) $r['service_id'],
            serviceTitle: (string) $r['service_title'],
            requiresApproval: (bool) $r['requires_approval'],
            meetUrl: self::nullable($r['meet_url']),
            gcalEventId: self::nullable($r['gcal_event_id']),
            gcalCalendarId: self::nullable($r['gcal_calendar_id']),
            calendarConnected: (bool) ($r['calendar_connected'] ?? false),
            gatewayRef: self::nullable($r['gateway_ref'] ?? null),
            gatewayUrl: self::nullable($r['gateway_url'] ?? null),
        );
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
