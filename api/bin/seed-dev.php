<?php

declare(strict_types=1);

// LOCAL DEVELOPMENT ONLY. Adds a demo provider, weekly hours, an owner user and the §8 service
// templates. Every contact and payment detail is a placeholder. Refuses to run on a database
// that already has providers.
// Usage: php bin/seed-dev.php

use ConsultDesk\Infra\Db;
use ConsultDesk\Infra\DbConfig;
use ConsultDesk\Seed\ServiceTemplates;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = Db::connect(DbConfig::fromEnv(getenv()))->pdo();

$count = $pdo->prepare('SELECT COUNT(*) FROM providers');
$count->execute();
if ((int) $count->fetchColumn() > 0) {
    fwrite(STDERR, "Database already has providers; not seeding.\n");
    exit(1);
}

$insert = static function (string $table, array $row) use ($pdo): int {
    $columns = array_keys($row);
    $pdo->prepare(sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $columns),
        implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
    ))->execute($row);

    return (int) $pdo->lastInsertId();
};

$pdo->beginTransaction();

$insert('users', [
    'email' => 'owner@example.test',
    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),
    'role' => 'owner',
]);

$providerId = $insert('providers', [
    'slug' => 'demo',
    'name' => 'Dr. Demo Placeholder',
    'title' => 'Pathologist · AI researcher · Medical educator',
    'bio' => 'Placeholder profile for local development.',
    'timezone' => 'Asia/Kolkata',
    'whatsapp' => '+910000000000',
    'notify_email' => 'provider@example.test',
    'upi_vpa' => 'placeholder@upi',
    'upi_payee_name' => 'Demo Placeholder',
]);

foreach ([1, 2, 3, 4, 5] as $weekday) {
    $insert('availability_rules', ['provider_id' => $providerId, 'weekday' => $weekday, 'start_time' => '10:00', 'end_time' => '13:00']);
    $insert('availability_rules', ['provider_id' => $providerId, 'weekday' => $weekday, 'start_time' => '16:00', 'end_time' => '19:00']);
}
$insert('availability_rules', ['provider_id' => $providerId, 'weekday' => 6, 'start_time' => '10:00', 'end_time' => '13:00']);

foreach (ServiceTemplates::all() as $order => $service) {
    $insert('services', [
        'provider_id' => $providerId,
        'slug' => $service['slug'],
        'title' => $service['title'],
        'tagline' => $service['tagline'],
        'duration_min' => $service['duration_min'],
        'price_minor' => $service['price_minor'],
        'currency' => $service['currency'],
        'requires_approval' => $service['requires_approval'] ? 1 : 0,
        'payment_methods' => json_encode($service['payment_methods'], JSON_THROW_ON_ERROR),
        'questions' => json_encode($service['questions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'sort_order' => $order,
    ]);
}

$pdo->commit();
echo "Seeded provider 'demo' with " . count(ServiceTemplates::all()) . " services.\n";
