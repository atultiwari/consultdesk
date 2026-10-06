<?php

declare(strict_types=1);

namespace ConsultDesk\Domain\Booking;

use InvalidArgumentException;

/**
 * Who performed an action, for the audit log and confirmed_by.
 */
final class Actor
{
    public function __construct(
        public readonly ActorType $type,
        public readonly ?int $id = null,
    ) {
        if ($type === ActorType::User && ($id === null || $id < 1)) {
            throw new InvalidArgumentException('A user actor needs a user id.');
        }
    }

    public static function user(int $id): self
    {
        return new self(ActorType::User, $id);
    }

    public static function system(): self
    {
        return new self(ActorType::System);
    }

    /** A payment gateway telling us about a payment. */
    public static function webhook(): self
    {
        return new self(ActorType::Webhook);
    }

    public static function customer(): self
    {
        return new self(ActorType::Customer);
    }
}
