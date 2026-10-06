<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Domain\Booking\BookingStatus;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Domain\Booking\StatusMachine;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Read side of the admin booking screens: the dashboard queues, the filtered list and one booking
 * with its history. Every query takes the viewer's provider scope (null = every provider).
 */
final class AdminBookings
{
    /** Panel action => target status, in the order buttons are shown. */
    public const ACTIONS = [
        'confirm' => BookingStatus::Confirmed,
        'reject' => BookingStatus::Rejected,
        'complete' => BookingStatus::Completed,
        'no-show' => BookingStatus::NoShow,
        'cancel' => BookingStatus::Cancelled,
    ];
    private const QUEUE_LIMIT = 50;
    private const UPCOMING_LIMIT = 10;
    private const SQL = 'Y-m-d H:i:s';
    private const ISO = 'Y-m-d\TH:i:s\Z';
    private const SELECT = 'SELECT b.id, b.ref, b.status, b.payment_method, b.start_at, b.end_at, b.customer_name,
            b.customer_email, b.amount_minor, b.currency, b.utr, b.hold_expires_at,
            p.id AS provider_id, p.name AS provider_name, s.title AS service_title
        FROM bookings b
        JOIN providers p ON p.id = b.provider_id
        JOIN services s ON s.id = b.service_id';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * What needs a decision now, and what is coming up. "Today" is the viewer's calendar day.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function dashboard(?int $providerScope, DateTimeImmutable $now, DateTimeZone $viewerTimezone): array
    {
        $dayStart = $now->setTimezone($viewerTimezone)->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
        $dayEnd = $dayStart->modify('+1 day');
        $pendingLive = 'b.hold_expires_at > :now';

        return [
            'to_verify' => $this->rows("b.status = 'awaiting_verification' AND {$pendingLive}", ['now' => $now->format(self::SQL)], $providerScope, 'b.hold_expires_at', self::QUEUE_LIMIT),
            'to_approve' => $this->rows("b.status = 'held' AND b.payment_method = 'free' AND {$pendingLive}", ['now' => $now->format(self::SQL)], $providerScope, 'b.start_at', self::QUEUE_LIMIT),
            'today' => $this->rows("b.status IN ('confirmed', 'completed', 'no_show') AND b.start_at >= :day_start AND b.start_at < :day_end", [
                'day_start' => $dayStart->format(self::SQL),
                'day_end' => $dayEnd->format(self::SQL),
            ], $providerScope, 'b.start_at', self::QUEUE_LIMIT),
            'upcoming' => $this->rows("b.status = 'confirmed' AND b.start_at >= :day_end", ['day_end' => $dayEnd->format(self::SQL)], $providerScope, 'b.start_at', self::UPCOMING_LIMIT),
        ];
    }

    /**
     * @return array{list<array<string, mixed>>, int} rows of the requested page and the total count
     */
    public function search(BookingFilter $filter): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filter->providerId !== null) {
            $where[] = 'b.provider_id = :provider_id';
            $params['provider_id'] = $filter->providerId;
        }
        if ($filter->status !== null) {
            $where[] = 'b.status = :status';
            $params['status'] = $filter->status->value;
        }
        if ($filter->from !== null) {
            $where[] = 'b.start_at >= :from';
            $params['from'] = $filter->from->format(self::SQL);
        }
        if ($filter->to !== null) {
            $where[] = 'b.start_at < :to';
            $params['to'] = $filter->to->format(self::SQL);
        }
        if ($filter->search !== null) {
            $where[] = '(b.ref LIKE :q1 OR b.customer_email LIKE :q2 OR b.customer_name LIKE :q3 OR b.utr LIKE :q4 OR b.customer_phone LIKE :q5)';
            $like = '%' . addcslashes($filter->search, '%_\\') . '%';
            foreach (range(1, 5) as $i) {
                $params["q{$i}"] = $like;
            }
        }
        $condition = implode(' AND ', $where);

        [$scopedCondition, $scopedParams] = self::scoped($condition, $filter->providerScope, $params);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM bookings b WHERE ' . $scopedCondition);
        $count->execute($scopedParams);
        $offset = ($filter->page - 1) * $filter->perPage;

        return [
            $this->rows($condition, $params, $filter->providerScope, 'b.start_at DESC, b.id DESC', $filter->perPage, $offset),
            (int) $count->fetchColumn(),
        ];
    }

    /**
     * One booking with its answers and audit history, or null if it is outside the viewer's scope.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $bookingId, AdminUser $viewer, DateTimeImmutable $now): ?array
    {
        $providerScope = $viewer->providerScope();
        [$condition, $params] = self::scoped('b.id = :id', $providerScope, ['id' => $bookingId]);
        $statement = $this->pdo->prepare(
            'SELECT b.id, b.customer_phone, b.customer_timezone, b.answers, b.meet_url, b.confirmed_at, s.questions
             FROM bookings b JOIN services s ON s.id = b.service_id WHERE ' . $condition,
        );
        $statement->execute($params);
        $extra = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($extra)) {
            return null;
        }
        $row = $this->rows('b.id = :id', ['id' => $bookingId], $providerScope, 'b.id', 1)[0];
        $answers = json_decode((string) $extra['answers'], true, 16, JSON_THROW_ON_ERROR);
        $questions = json_decode((string) $extra['questions'], true, 8, JSON_THROW_ON_ERROR);
        $lapsed = $row['hold_expires_at'] !== null && $row['hold_expires_at'] <= $now->format(self::ISO);

        return [
            ...$row,
            'customer' => [
                'name' => $row['customer_name'],
                'email' => $row['customer_email'],
                'phone' => $extra['customer_phone'],
                'timezone' => $extra['customer_timezone'],
            ],
            'answers' => self::labelledAnswers(is_array($answers) ? $answers : [], is_array($questions) ? $questions : []),
            'meet_url' => $extra['meet_url'],
            'confirmed_at' => self::iso($extra['confirmed_at']),
            'history' => $this->history($bookingId, $viewer),
            'actions' => self::actionsFor(BookingStatus::from((string) $row['status']), PaymentMethod::from((string) $row['payment_method']), $lapsed),
        ];
    }

    /**
     * The panel actions allowed from a status. A pending booking whose hold has lapsed can only be
     * cancelled: its slot may already have been given to someone else.
     *
     * @return list<string>
     */
    public static function actionsFor(BookingStatus $status, PaymentMethod $method, bool $holdLapsed = false): array
    {
        $actions = [];
        foreach (self::ACTIONS as $action => $target) {
            $allowed = StatusMachine::canTransition($status, $target, $method)
                && !($holdLapsed && $target !== BookingStatus::Cancelled)
                // A payment link is confirmed by the gateway's webhook, never by hand.
                && !($method === PaymentMethod::RazorpayLink && $target === BookingStatus::Confirmed);
            if ($allowed) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * Answers in the order the questions are asked, each with the question's current label.
     * Answers to questions removed since are kept, labelled by their id.
     *
     * @param array<array-key, mixed> $answers
     * @param array<array-key, mixed> $questions
     *
     * @return list<array{id: string, label: string, value: mixed}>
     */
    private static function labelledAnswers(array $answers, array $questions): array
    {
        $labels = [];
        foreach ($questions as $question) {
            if (is_array($question) && is_string($question['id'] ?? null)) {
                $labels[$question['id']] = is_string($question['label'] ?? null) ? $question['label'] : $question['id'];
            }
        }
        $ordered = [...array_intersect_key($labels, $answers), ...array_diff_key($answers, $labels)];
        $out = [];
        foreach (array_keys($ordered) as $id) {
            $out[] = ['id' => (string) $id, 'label' => $labels[$id] ?? (string) $id, 'value' => $answers[$id]];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(int $bookingId, AdminUser $viewer): array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.action, a.actor_type, a.data, a.created_at, u.id AS user_id, u.email, u.name, u.role
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.actor_id AND a.actor_type IN ('user', 'telegram')
             WHERE a.entity_type = 'booking' AND a.entity_id = :id
             ORDER BY a.id",
        );
        $statement->execute(['id' => $bookingId]);

        return array_values(array_map(static function (array $r) use ($viewer): array {
            $data = $r['data'] === null ? [] : json_decode((string) $r['data'], true, 16, JSON_THROW_ON_ERROR);

            return [
                'action' => (string) $r['action'],
                'actor_type' => (string) $r['actor_type'],
                'actor' => self::actorName($r, $viewer),
                'data' => is_array($data) ? $data : [],
                'at' => self::iso($r['created_at']),
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Who acted, as the viewer may see them: staff see names or emails; a provider sees colleagues'
     * names, or just their role, but never their email addresses.
     *
     * @param array<string, mixed> $r
     */
    private static function actorName(array $r, AdminUser $viewer): ?string
    {
        if ($r['user_id'] === null) {
            return null;
        }
        if ($r['name'] !== null && $r['name'] !== '') {
            return (string) $r['name'];
        }

        return $viewer->isStaff() || (int) $r['user_id'] === $viewer->id ? (string) $r['email'] : ucfirst((string) $r['role']);
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $condition, array $params, ?int $providerScope, string $order, int $limit, int $offset = 0): array
    {
        [$scopedCondition, $scopedParams] = self::scoped($condition, $providerScope, $params);
        $statement = $this->pdo->prepare(sprintf(
            '%s WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
            self::SELECT,
            $scopedCondition,
            $order,
            $limit,
            $offset,
        ));
        $statement->execute($scopedParams);

        return array_values(array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'ref' => (string) $r['ref'],
            'status' => (string) $r['status'],
            'payment_method' => (string) $r['payment_method'],
            'start' => self::iso($r['start_at']),
            'end' => self::iso($r['end_at']),
            'customer_name' => (string) $r['customer_name'],
            'customer_email' => (string) $r['customer_email'],
            'amount_minor' => (int) $r['amount_minor'],
            'currency' => (string) $r['currency'],
            'utr' => $r['utr'],
            'hold_expires_at' => self::iso($r['hold_expires_at']),
            'provider' => ['id' => (int) $r['provider_id'], 'name' => (string) $r['provider_name']],
            'service_title' => (string) $r['service_title'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * Adds the viewer's provider scope to a condition.
     *
     * @param array<string, scalar> $params
     *
     * @return array{string, array<string, scalar>}
     */
    private static function scoped(string $condition, ?int $providerScope, array $params): array
    {
        if ($providerScope === null) {
            return [$condition, $params];
        }

        return ["({$condition}) AND b.provider_id = :scope_provider", [...$params, 'scope_provider' => $providerScope]];
    }

    private static function iso(mixed $sqlDateTime): ?string
    {
        return $sqlDateTime === null ? null : (new DateTimeImmutable((string) $sqlDateTime, new DateTimeZone('UTC')))->format(self::ISO);
    }
}
