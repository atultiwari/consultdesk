<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Domain\Booking;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Domain\Booking\ActorType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActorTest extends TestCase
{
    public function testFactories(): void
    {
        self::assertSame(ActorType::System, Actor::system()->type);
        self::assertNull(Actor::system()->id);
        self::assertSame(42, Actor::user(42)->id);
        self::assertSame(ActorType::User, Actor::user(42)->type);
        self::assertSame(ActorType::Customer, Actor::customer()->type);
    }

    public function testUserActorNeedsAPositiveId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Actor(ActorType::User, null);
    }
}
