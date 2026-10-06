<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Telegram;

use ConsultDesk\Telegram\AlertKind;
use ConsultDesk\Telegram\CallbackAction;
use ConsultDesk\Telegram\CallbackData;
use PHPUnit\Framework\TestCase;

final class CallbackDataTest extends TestCase
{
    public function testRoundTripsEveryAction(): void
    {
        foreach (CallbackAction::cases() as $action) {
            foreach (AlertKind::cases() as $kind) {
                $encoded = (new CallbackData($action, 123456, $kind))->encode();
                self::assertLessThanOrEqual(64, strlen($encoded));

                $decoded = CallbackData::parse($encoded);
                self::assertNotNull($decoded);
                self::assertSame($action, $decoded->action);
                self::assertSame(123456, $decoded->bookingId);
                self::assertSame($kind, $decoded->kind);
            }
        }
    }

    public function testRejectsAnythingElse(): void
    {
        foreach (['', 'c:', 'c:12', 'zz:v:12', 'c:v:-1', 'c:v:12:extra', 'c:q:12', 'c:v:abc'] as $bad) {
            self::assertNull(CallbackData::parse($bad), $bad);
        }
    }
}
