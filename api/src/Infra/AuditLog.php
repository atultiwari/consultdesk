<?php

declare(strict_types=1);

namespace ConsultDesk\Infra;

use ConsultDesk\Domain\Booking\Actor;
use PDO;

/**
 * Who did what, for anything other than booking status changes (those are audited by BookingService).
 */
final class AuditLog
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function record(Actor $actor, string $action, string $entityType, ?int $entityId, array $data = []): void
    {
        $this->pdo->prepare(
            'INSERT INTO audit_log (actor_type, actor_id, action, entity_type, entity_id, data, created_at)
             VALUES (:actor_type, :actor_id, :action, :entity_type, :entity_id, :data, :now)',
        )->execute([
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'data' => $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }
}
