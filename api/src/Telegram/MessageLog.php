<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

use ConsultDesk\Infra\Clock;
use PDO;

/**
 * Alerts that still carry action buttons, so they can be updated when the booking is settled.
 */
final class MessageLog
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function record(int $bookingId, string $chatId, int $messageId): void
    {
        $this->pdo->prepare(
            'INSERT INTO telegram_messages (booking_id, chat_id, message_id, created_at) VALUES (:booking, :chat, :message, :created)
             ON DUPLICATE KEY UPDATE booking_id = booking_id',
        )->execute([
            'booking' => $bookingId,
            'chat' => $chatId,
            'message' => $messageId,
            'created' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<array{chat: string, message: int}>
     */
    public function forBooking(int $bookingId): array
    {
        $statement = $this->pdo->prepare('SELECT chat_id, message_id FROM telegram_messages WHERE booking_id = :booking ORDER BY id');
        $statement->execute(['booking' => $bookingId]);

        return array_values(array_map(
            static fn(array $r): array => ['chat' => (string) $r['chat_id'], 'message' => (int) $r['message_id']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    public function forget(int $bookingId, string $chatId, int $messageId): void
    {
        $this->pdo->prepare('DELETE FROM telegram_messages WHERE booking_id = :booking AND chat_id = :chat AND message_id = :message')
            ->execute(['booking' => $bookingId, 'chat' => $chatId, 'message' => $messageId]);
    }
}
