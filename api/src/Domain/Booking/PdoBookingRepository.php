<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use ConsultDesk\Domain\Availability\BookingRules;
use ConsultDesk\Domain\Availability\Interval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final class PdoBookingRepository implements BookingRepository
{
    private const SQL_DATETIME = 'Y-m-d H:i:s';
    private const MYSQL_DUPLICATE_KEY = 1062;
    private const BLOCKING = "(status = 'confirmed' OR (status IN ('held', 'awaiting_verification') AND hold_expires_at > :now))";

    public function __construct(private readonly PDO $pdo) {}

    public function lockActiveProvider(int $providerId): ?ProviderRecord
    {
        $row = $this->fetchOne(
            'SELECT id, timezone, min_notice_min, horizon_days, buffer_before, buffer_after, slot_interval, max_per_day
             FROM providers WHERE id = :id AND active = 1 FOR UPDATE',
            ['id' => $providerId],
        );
        if ($row === null) {
            return null;
        }

        return new ProviderRecord(
            (int) $row['id'],
            new DateTimeZone((string) $row['timezone']),
            new BookingRules(
                (int) $row['min_notice_min'],
                (int) $row['horizon_days'],
                (int) $row['buffer_before'],
                (int) $row['buffer_after'],
                (int) $row['slot_interval'],
                $row['max_per_day'] === null ? null : (int) $row['max_per_day'],
            ),
        );
    }

    public function findActiveService(int $serviceId): ?ServiceRecord
    {
        $row = $this->fetchOne(
            'SELECT id, provider_id, duration_min, price_minor, currency, requires_approval, payment_methods
             FROM services WHERE id = :id AND active = 1',
            ['id' => $serviceId],
        );
        if ($row === null) {
            return null;
        }

        $methods = json_decode((string) $row['payment_methods'], true, 4, JSON_THROW_ON_ERROR);

        return new ServiceRecord(
            (int) $row['id'],
            (int) $row['provider_id'],
            (int) $row['duration_min'],
            (int) $row['price_minor'],
            (string) $row['currency'],
            (bool) $row['requires_approval'],
            array_values(array_filter(array_map(
                static fn(mixed $m): ?PaymentMethod => is_string($m) ? PaymentMethod::tryFrom($m) : null,
                is_array($methods) ? $methods : [],
            ))),
        );
    }

    public function countBlockingOverlapping(int $providerId, Interval $range, DateTimeImmutable $now): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM bookings
             WHERE provider_id = :provider AND start_at < :range_end AND end_at > :range_start AND ' . self::BLOCKING,
            $providerId,
            $range,
            $now,
        );
    }

    public function countBlockingStarting(int $providerId, Interval $range, DateTimeImmutable $now): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM bookings
             WHERE provider_id = :provider AND start_at >= :range_start AND start_at < :range_end AND ' . self::BLOCKING,
            $providerId,
            $range,
            $now,
        );
    }

    public function insert(NewBooking $booking): int
    {
        $created = $booking->createdAt->format(self::SQL_DATETIME);
        try {
            $this->pdo->prepare(
                'INSERT INTO bookings (ref, public_token_hash, provider_id, service_id, start_at, end_at,
                    customer_name, customer_email, customer_phone, customer_timezone, answers,
                    amount_minor, currency, payment_method, status, hold_expires_at,
                    status_changed_at, created_at, updated_at)
                 VALUES (:ref, :token_hash, :provider, :service, :start_at, :end_at,
                    :name, :email, :phone, :timezone, :answers,
                    :amount, :currency, :method, :status, :hold_expires_at,
                    :status_changed_at, :created_at, :updated_at)',
            )->execute([
                'ref' => $booking->ref,
                'token_hash' => $booking->publicTokenHash,
                'provider' => $booking->providerId,
                'service' => $booking->serviceId,
                'start_at' => $booking->slot->start->format(self::SQL_DATETIME),
                'end_at' => $booking->slot->end->format(self::SQL_DATETIME),
                'name' => $booking->customer->name,
                'email' => $booking->customer->email,
                'phone' => $booking->customer->phone,
                'timezone' => $booking->customer->timezone,
                'answers' => json_encode((object) $booking->answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'amount' => $booking->amountMinor,
                'currency' => $booking->currency,
                'method' => $booking->paymentMethod->value,
                'status' => BookingStatus::Held->value,
                'hold_expires_at' => $booking->holdExpiresAt->format(self::SQL_DATETIME),
                'status_changed_at' => $created,
                'created_at' => $created,
                'updated_at' => $created,
            ]);
        } catch (PDOException $e) {
            if (self::isDuplicateKey($e, 'uq_bookings_ref')) {
                throw new RefCollision($booking->ref, 0, $e);
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function lockBooking(int $bookingId): ?BookingRecord
    {
        $row = $this->fetchOne(
            'SELECT id, status, payment_method, hold_expires_at FROM bookings WHERE id = :id FOR UPDATE',
            ['id' => $bookingId],
        );
        if ($row === null) {
            return null;
        }

        return new BookingRecord(
            (int) $row['id'],
            BookingStatus::from((string) $row['status']),
            PaymentMethod::from((string) $row['payment_method']),
            $row['hold_expires_at'] === null ? null : self::utc((string) $row['hold_expires_at']),
        );
    }

    public function markAwaitingVerification(int $bookingId, Utr $utr, DateTimeImmutable $holdExpiresAt, DateTimeImmutable $now): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE bookings SET status = :status, utr = :utr, hold_expires_at = :hold,
                    status_changed_at = :changed_at, updated_at = :updated_at WHERE id = :id',
            )->execute([
                'status' => BookingStatus::AwaitingVerification->value,
                'utr' => $utr->value,
                'hold' => $holdExpiresAt->format(self::SQL_DATETIME),
                'changed_at' => $now->format(self::SQL_DATETIME),
                'updated_at' => $now->format(self::SQL_DATETIME),
                'id' => $bookingId,
            ]);
        } catch (PDOException $e) {
            if (self::isDuplicateKey($e, 'uq_bookings_utr')) {
                throw new DuplicateUtr();
            }
            throw $e;
        }
    }

    public function markConfirmed(int $bookingId, ?int $confirmedByUserId, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE bookings SET status = :status, confirmed_by = :by, confirmed_at = :confirmed_at, hold_expires_at = NULL,
                status_changed_at = :changed_at, updated_at = :updated_at WHERE id = :id',
        )->execute([
            'status' => BookingStatus::Confirmed->value,
            'by' => $confirmedByUserId,
            'confirmed_at' => $now->format(self::SQL_DATETIME),
            'changed_at' => $now->format(self::SQL_DATETIME),
            'updated_at' => $now->format(self::SQL_DATETIME),
            'id' => $bookingId,
        ]);
    }

    public function setStatus(int $bookingId, BookingStatus $status, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'UPDATE bookings SET status = :status, status_changed_at = :changed_at, updated_at = :updated_at WHERE id = :id',
        )->execute([
            'status' => $status->value,
            'changed_at' => $now->format(self::SQL_DATETIME),
            'updated_at' => $now->format(self::SQL_DATETIME),
            'id' => $bookingId,
        ]);
    }

    public function lockLapsedPending(DateTimeImmutable $now): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM bookings
             WHERE status IN ('held', 'awaiting_verification') AND hold_expires_at <= :now
             ORDER BY id FOR UPDATE",
        );
        $statement->execute(['now' => $now->format(self::SQL_DATETIME)]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function audit(Actor $actor, string $action, int $bookingId, array $data, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            "INSERT INTO audit_log (actor_type, actor_id, action, entity_type, entity_id, data, created_at)
             VALUES (:actor_type, :actor_id, :action, 'booking', :entity_id, :data, :now)",
        )->execute([
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_id' => $bookingId,
            'data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'now' => $now->format(self::SQL_DATETIME),
        ]);
    }

    private function count(string $sql, int $providerId, Interval $range, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'provider' => $providerId,
            'range_start' => $range->start->format(self::SQL_DATETIME),
            'range_end' => $range->end->format(self::SQL_DATETIME),
            'now' => $now->format(self::SQL_DATETIME),
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function isDuplicateKey(PDOException $e, string $key): bool
    {
        return ($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_KEY && str_contains($e->getMessage(), $key);
    }

    private static function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
