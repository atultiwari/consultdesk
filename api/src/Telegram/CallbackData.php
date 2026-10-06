<?php

declare(strict_types=1);

namespace ConsultDesk\Telegram;

/**
 * What a button press means: "{action}:{kind}:{bookingId}", e.g. "c:v:42".
 * It is not trusted on its own: the webhook checks the secret header and that the chat may act on the booking.
 */
final class CallbackData
{
    private const PATTERN = '/^([a-z]):([a-z]):([1-9]\d{0,18})$/';

    public function __construct(
        public readonly CallbackAction $action,
        public readonly int $bookingId,
        public readonly AlertKind $kind,
    ) {}

    public static function parse(string $data): ?self
    {
        if (preg_match(self::PATTERN, $data, $m) !== 1) {
            return null;
        }
        $action = CallbackAction::tryFrom($m[1]);
        $kind = AlertKind::tryFrom($m[2]);

        return $action === null || $kind === null ? null : new self($action, (int) $m[3], $kind);
    }

    public function encode(): string
    {
        return sprintf('%s:%s:%d', $this->action->value, $this->kind->value, $this->bookingId);
    }
}
