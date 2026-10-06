<?php

declare(strict_types=1);

/**
 * Child process for ConcurrentHoldTest: waits for a shared start time, then tries one hold().
 * Usage: php hold_worker.php <providerId> <serviceId> <startIso> <startAtUnixFloat>
 * Prints "ok <ref>" or the short class name of the exception.
 */

use ConsultDesk\Domain\Booking\BookingService;
use ConsultDesk\Domain\Booking\Customer;
use ConsultDesk\Domain\Booking\HoldRequest;
use ConsultDesk\Domain\Booking\PaymentMethod;
use ConsultDesk\Domain\Booking\PdoBookingRepository;
use ConsultDesk\Domain\Booking\RandomRefGenerator;
use ConsultDesk\Infra\Crypto;
use ConsultDesk\Infra\Db;
use ConsultDesk\Infra\DbConfig;
use ConsultDesk\Infra\FrozenClock;
use ConsultDesk\Notify\Outbox;
use ConsultDesk\Notify\OutboxBookingEvents;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$args = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
if (count($args) !== 5) {
    fwrite(STDERR, "Usage: php hold_worker.php <providerId> <serviceId> <startIso> <startAtUnixFloat>\n");
    exit(2);
}
[, $providerId, $serviceId, $startIso, $goAt] = array_map('strval', $args);

$db = Db::connect(DbConfig::fromEnv(getenv(), 'DB_TEST_NAME'));
$clock = new FrozenClock('2026-10-05T00:00Z');
$service = new BookingService(
    $db,
    new PdoBookingRepository($db->pdo()),
    $clock,
    new RandomRefGenerator(),
    new OutboxBookingEvents(new Outbox($db->pdo(), $clock)),
    new Crypto(str_repeat('t', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
);
$request = new HoldRequest(
    providerId: (int) $providerId,
    serviceId: (int) $serviceId,
    start: new DateTimeImmutable($startIso),
    customer: new Customer('Race Placeholder', 'race@example.test'),
    paymentMethod: PaymentMethod::Upi,
);

$wait = (float) $goAt - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    echo 'ok ' . $service->hold($request)->ref;
} catch (Throwable $e) {
    echo (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
}
