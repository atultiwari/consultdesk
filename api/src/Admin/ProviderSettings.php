<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use PDO;

/**
 * Providers as the admin panel edits them: profile, contact, UPI details and booking rules.
 */
final class ProviderSettings
{
    /** Columns the panel may write, in the order they are read from a request. */
    public const COLUMNS = [
        'slug', 'name', 'title', 'bio', 'whatsapp', 'notify_email', 'upi_vpa', 'upi_payee_name', 'timezone', 'active', 'sort_order',
        'min_notice_min', 'horizon_days', 'buffer_before', 'buffer_after', 'slot_interval', 'max_per_day',
    ];
    public const RULE_COLUMNS = ['min_notice_min', 'horizon_days', 'buffer_before', 'buffer_after', 'slot_interval', 'max_per_day'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(?int $providerScope): array
    {
        $sql = 'SELECT * FROM providers' . ($providerScope === null ? '' : ' WHERE id = :id') . ' ORDER BY sort_order, name, id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($providerScope === null ? [] : ['id' => $providerScope]);

        return array_values(array_map(self::present(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM providers WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::present($row) : null;
    }

    public function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM providers WHERE slug = :slug AND id <> :id');
        $statement->execute(['slug' => $slug, 'id' => $exceptId ?? 0]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array<string, scalar|null> $values column => value, keys from COLUMNS
     */
    public function create(array $values): int
    {
        $values = self::writable($values);
        $columns = array_keys($values);
        $this->pdo->prepare(sprintf(
            'INSERT INTO providers (%s) VALUES (%s)',
            implode(', ', array_map(static fn(string $c): string => "`{$c}`", $columns)),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
        ))->execute(self::bindable($values));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, scalar|null> $values column => value, keys from COLUMNS
     */
    public function update(int $id, array $values): void
    {
        $values = self::writable($values);
        if ($values === []) {
            return;
        }
        $this->pdo->prepare(sprintf(
            'UPDATE providers SET %s WHERE id = :id',
            implode(', ', array_map(static fn(string $c): string => "`{$c}` = :{$c}", array_keys($values))),
        ))->execute([...self::bindable($values), 'id' => $id]);
    }

    /**
     * @param string|null $photoPath e.g. "api/media/<name>.webp", or null for no photo
     */
    public function setPhoto(int $id, ?string $photoPath): void
    {
        $this->pdo->prepare('UPDATE providers SET photo_path = :path WHERE id = :id')->execute(['path' => $photoPath, 'id' => $id]);
    }

    /**
     * @param array<string, scalar|null> $values
     *
     * @return array<string, scalar|null>
     */
    private static function writable(array $values): array
    {
        return array_intersect_key($values, array_flip(self::COLUMNS));
    }

    /**
     * @param array<string, scalar|null> $values
     *
     * @return array<string, scalar|null>
     */
    private static function bindable(array $values): array
    {
        return array_map(static fn($v) => is_bool($v) ? (int) $v : $v, $values);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'name' => (string) $r['name'],
            'title' => $r['title'],
            'bio' => $r['bio'],
            'photo_url' => $r['photo_path'] === null ? null : '/' . ltrim((string) $r['photo_path'], '/'),
            'timezone' => (string) $r['timezone'],
            'active' => (bool) $r['active'],
            'sort_order' => (int) $r['sort_order'],
            'whatsapp' => $r['whatsapp'],
            'notify_email' => $r['notify_email'],
            'upi_vpa' => $r['upi_vpa'],
            'upi_payee_name' => $r['upi_payee_name'],
            'telegram_linked' => $r['telegram_chat_id'] !== null,
            'rules' => [
                'min_notice_min' => (int) $r['min_notice_min'],
                'horizon_days' => (int) $r['horizon_days'],
                'buffer_before' => (int) $r['buffer_before'],
                'buffer_after' => (int) $r['buffer_after'],
                'slot_interval' => (int) $r['slot_interval'],
                'max_per_day' => $r['max_per_day'] === null ? null : (int) $r['max_per_day'],
            ],
        ];
    }
}
