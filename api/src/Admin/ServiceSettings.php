<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use PDO;

/**
 * A provider's services as the admin panel edits them, including the intake questions.
 */
final class ServiceSettings
{
    public const COLUMNS = [
        'slug', 'title', 'tagline', 'description', 'audience', 'highlight', 'duration_min', 'price_minor',
        'requires_approval', 'payment_methods', 'questions', 'active', 'sort_order',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forProvider(int $providerId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM services WHERE provider_id = :id ORDER BY sort_order, id');
        $statement->execute(['id' => $providerId]);

        return array_values(array_map(self::present(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM services WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::present($row) : null;
    }

    /**
     * Puts a provider's sessions in this order (every one of their sessions, each once).
     *
     * @param list<int> $ids
     *
     * @return bool false when the list isn't exactly this provider's sessions
     */
    public function reorder(int $providerId, array $ids): bool
    {
        $this->pdo->beginTransaction();
        try {
            // Locked, so a session added meanwhile can't slip past the check.
            $lock = $this->pdo->prepare('SELECT id FROM services WHERE provider_id = :p FOR UPDATE');
            $lock->execute(['p' => $providerId]);
            $mine = array_map('intval', $lock->fetchAll(PDO::FETCH_COLUMN));
            $sorted = $ids;
            sort($sorted);
            sort($mine);
            if ($sorted !== $mine) {
                $this->pdo->rollBack();

                return false;
            }
            $statement = $this->pdo->prepare('UPDATE services SET sort_order = :order WHERE id = :id AND provider_id = :provider');
            foreach ($ids as $i => $id) {
                $statement->execute(['order' => $i + 1, 'id' => $id, 'provider' => $providerId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    public function slugTaken(int $providerId, string $slug, ?int $exceptId = null): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM services WHERE provider_id = :provider AND slug = :slug AND id <> :id');
        $statement->execute(['provider' => $providerId, 'slug' => $slug, 'id' => $exceptId ?? 0]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array<string, mixed> $values column => value; payment_methods and questions as arrays
     */
    public function create(int $providerId, array $values): int
    {
        $values = [...self::row($values), 'provider_id' => $providerId];
        if (!array_key_exists('sort_order', $values)) {
            // New sessions go to the end of the teacher's list.
            $last = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM services WHERE provider_id = :p');
            $last->execute(['p' => $providerId]);
            $values['sort_order'] = (int) $last->fetchColumn() + 1;
        }
        $columns = array_keys($values);
        $this->pdo->prepare(sprintf(
            'INSERT INTO services (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
        ))->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values column => value; payment_methods and questions as arrays
     */
    public function update(int $id, array $values): void
    {
        $row = self::row($values);
        if ($row === []) {
            return;
        }
        $this->pdo->prepare(sprintf(
            'UPDATE services SET %s WHERE id = :id',
            implode(', ', array_map(static fn(string $c): string => "{$c} = :{$c}", array_keys($row))),
        ))->execute([...$row, 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, scalar|null>
     */
    private static function row(array $values): array
    {
        $row = [];
        foreach (array_intersect_key($values, array_flip(self::COLUMNS)) as $column => $value) {
            $row[$column] = match (true) {
                is_array($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                is_bool($value) => (int) $value,
                is_scalar($value) || $value === null => $value,
                default => null,
            };
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function present(array $r): array
    {
        $methods = json_decode((string) $r['payment_methods'], true, 4, JSON_THROW_ON_ERROR);
        $questions = json_decode((string) $r['questions'], true, 8, JSON_THROW_ON_ERROR);

        return [
            'id' => (int) $r['id'],
            'provider_id' => (int) $r['provider_id'],
            'slug' => (string) $r['slug'],
            'title' => (string) $r['title'],
            'tagline' => $r['tagline'],
            'description' => $r['description'],
            'audience' => $r['audience'],
            'highlight' => $r['highlight'] ?? null,
            'duration_min' => (int) $r['duration_min'],
            'price_minor' => (int) $r['price_minor'],
            'currency' => (string) $r['currency'],
            'requires_approval' => (bool) $r['requires_approval'],
            'payment_methods' => is_array($methods) ? array_values($methods) : [],
            'questions' => is_array($questions) ? array_values($questions) : [],
            'active' => (bool) $r['active'],
            'sort_order' => (int) $r['sort_order'],
        ];
    }
}
