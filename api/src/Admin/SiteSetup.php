<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Infra\Clock;
use ConsultDesk\Infra\Db;
use ConsultDesk\Infra\Settings;
use ConsultDesk\Seed\ServiceTemplates;
use PDO;

/**
 * The owner's "Set up your site" wizard: one teacher or several, the (first) teacher's profile,
 * and starter sessions. Stored in settings key "setup".
 */
final class SiteSetup
{
    public const KEY = 'setup';
    public const SINGLE = 'single';
    public const MULTI = 'multi';
    /** Starter weekly hours (Monday–Friday mornings and evenings, Saturday morning), to edit later. */
    private const STARTER_HOURS = [[1, '10:00', '13:00'], [1, '16:00', '19:00'], [2, '10:00', '13:00'], [2, '16:00', '19:00'], [3, '10:00', '13:00'], [3, '16:00', '19:00'], [4, '10:00', '13:00'], [4, '16:00', '19:00'], [5, '10:00', '13:00'], [5, '16:00', '19:00'], [6, '10:00', '13:00']];

    public function __construct(
        private readonly Db $db,
        private readonly Settings $settings,
        private readonly ProviderSettings $providers,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{mode: ?string, completed: bool, provider_id: ?int}
     */
    public function stored(): array
    {
        $s = $this->settings->get(self::KEY);
        $mode = in_array($s['mode'] ?? null, [self::SINGLE, self::MULTI], true) ? $s['mode'] : null;

        return ['mode' => $mode, 'completed' => is_string($s['completed_at'] ?? null), 'provider_id' => is_int($s['provider_id'] ?? null) ? $s['provider_id'] : null];
    }

    public function isSingle(): bool
    {
        return $this->stored()['mode'] === self::SINGLE;
    }

    /**
     * @return array<string, mixed>|null the teacher set up in the wizard, else the first provider
     */
    public function teacher(): ?array
    {
        $id = $this->stored()['provider_id'];
        $provider = $id === null ? null : $this->providers->find($id);

        return $provider ?? ($this->providers->all(null)[0] ?? null);
    }

    public function activeProviders(): int
    {
        return self::count($this->db->pdo(), 'SELECT COUNT(*) FROM providers WHERE active = 1', []);
    }

    public function setMode(string $mode): void
    {
        $this->update(['mode' => $mode]);
    }

    /**
     * Creates the teacher, or updates the one already set up.
     *
     * @param array<string, scalar|null> $values provider columns
     */
    public function saveTeacher(array $values): int
    {
        $existing = $this->teacher();
        if ($existing !== null) {
            $this->providers->update((int) $existing['id'], $values);
            $id = (int) $existing['id'];
        } else {
            $id = $this->providers->create([...$values, 'slug' => $this->freeSlug((string) ($values['slug'] ?? $values['name'] ?? 'teacher'))]);
        }
        $this->update(['provider_id' => $id]);

        return $id;
    }

    /**
     * Adds the chosen starter sessions (with the owner's changes) and, if the teacher has no weekly
     * hours yet, a starter week.
     *
     * @param list<array{template: array<string, mixed>, title: ?string, duration_min: ?int, price_minor: ?int}> $choices
     */
    public function addSessions(int $providerId, array $choices): int
    {
        return $this->db->transaction(function (PDO $pdo) use ($providerId, $choices): int {
            $order = self::count($pdo, 'SELECT COALESCE(MAX(sort_order), 0) FROM services WHERE provider_id = :p', ['p' => $providerId]);
            $insert = $pdo->prepare(
                'INSERT INTO services (provider_id, slug, title, tagline, audience, duration_min, price_minor, currency, requires_approval, payment_methods, questions, active, sort_order)
                 VALUES (:provider, :slug, :title, :tagline, :audience, :duration, :price, :currency, :approval, :methods, :questions, 1, :order)',
            );
            foreach ($choices as $choice) {
                $t = $choice['template'];
                $price = $choice['price_minor'] ?? (int) $t['price_minor'];
                $methods = $price === 0 ? ['free'] : array_values(array_diff((array) $t['payment_methods'], ['free']) ?: ['upi']);
                $insert->execute([
                    'provider' => $providerId,
                    'slug' => $this->freeServiceSlug($providerId, (string) $t['slug']),
                    'title' => $choice['title'] ?? $t['title'],
                    'tagline' => $t['tagline'] ?? null,
                    'audience' => $t['audience'] ?? null,
                    'duration' => $choice['duration_min'] ?? $t['duration_min'],
                    'price' => $price,
                    'currency' => $t['currency'] ?? 'INR',
                    'approval' => (int) (bool) $t['requires_approval'],
                    'methods' => json_encode($methods, JSON_THROW_ON_ERROR),
                    'questions' => json_encode($t['questions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'order' => ++$order,
                ]);
            }
            $hasHours = self::count($pdo, 'SELECT COUNT(*) FROM availability_rules WHERE provider_id = :p', ['p' => $providerId]) > 0;
            if (!$hasHours) {
                $hours = $pdo->prepare('INSERT INTO availability_rules (provider_id, weekday, start_time, end_time) VALUES (:p, :d, :s, :e)');
                foreach (self::STARTER_HOURS as [$day, $start, $end]) {
                    $hours->execute(['p' => $providerId, 'd' => $day, 's' => $start, 'e' => $end]);
                }
            }

            return count($choices);
        });
    }

    public function complete(): void
    {
        $this->update(['completed_at' => $this->clock->now()->format(DATE_ATOM)]);
    }

    /**
     * @return list<array<string, mixed>> the template sets, without the bulky questions
     */
    public static function templateSets(): array
    {
        return array_map(static fn(array $set): array => [
            'key' => $set['key'],
            'label' => $set['label'],
            'description' => $set['description'],
            'templates' => array_map(static fn(array $t): array => [
                'key' => $t['key'],
                'title' => $t['title'],
                'tagline' => $t['tagline'],
                'audience' => $t['audience'] ?? null,
                'duration_min' => $t['duration_min'],
                'price_minor' => $t['price_minor'],
                'price_note' => $t['price_note'] ?? null,
                'requires_approval' => $t['requires_approval'],
            ], $set['templates']),
        ], ServiceTemplates::sets());
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function update(array $changes): void
    {
        $this->settings->put(self::KEY, [...$this->settings->get(self::KEY), ...$changes]);
    }

    private function freeSlug(string $from): string
    {
        $base = self::slug($from) ?: 'teacher';
        $slug = $base;
        for ($n = 2; $this->providers->slugTaken($slug); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function freeServiceSlug(int $providerId, string $base): string
    {
        $statement = $this->db->pdo()->prepare('SELECT COUNT(*) FROM services WHERE provider_id = :p AND slug = :s');
        $slug = $base;
        for ($n = 2; ; $n++) {
            $statement->execute(['p' => $providerId, 's' => $slug]);
            if ((int) $statement->fetchColumn() === 0) {
                return $slug;
            }
            $slug = "{$base}-{$n}";
        }
    }

    /**
     * @param array<string, int> $params
     */
    private static function count(PDO $pdo, string $sql, array $params): int
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private static function slug(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii === false ? $text : $ascii)), '-');

        return substr($slug, 0, 60);
    }
}
