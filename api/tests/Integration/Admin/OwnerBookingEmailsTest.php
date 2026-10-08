<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Admin;

use ConsultDesk\Domain\Booking\PdoBookingViews;
use ConsultDesk\Tests\Integration\Support\Fixtures;

final class OwnerBookingEmailsTest extends AdminTestCase
{
    public function testAnOwnerCanStopGettingCopiesOfBookingEmails(): void
    {
        $teacher = Fixtures::provider($this->pdo, ['slug' => 'demo', 'notify_email' => 'bookings@example.test']);
        $this->createUser('admin@example.test');
        $this->login('admin@example.test');
        $views = new PdoBookingViews($this->pdo);

        self::assertSame(['bookings@example.test', 'admin@example.test'], $views->staffEmailsForProvider($teacher), 'on by default');
        self::assertTrue($this->admin('GET', '/api/admin/me/notifications')[1]['data']['booking_emails']);

        [$status, $saved] = $this->admin('PUT', '/api/admin/me/notifications', ['booking_emails' => false]);
        self::assertSame([200, false], [$status, $saved['data']['booking_emails']]);
        self::assertSame(['bookings@example.test'], $views->staffEmailsForProvider($teacher), 'no copy for the owner');
        self::assertSame(['admin.booking_emails_changed'], self::column($this->pdo, "SELECT action FROM audit_log WHERE action LIKE 'admin.booking_emails%'"));
    }

    public function testOwnersStillGetThemWhenNobodyElseWould(): void
    {
        $teacher = Fixtures::provider($this->pdo, ['slug' => 'demo', 'notify_email' => null]);
        $this->createUser('admin@example.test');
        $this->pdo->exec("UPDATE users SET booking_emails = 0");

        self::assertSame(['admin@example.test'], (new PdoBookingViews($this->pdo))->staffEmailsForProvider($teacher), 'never lost');
    }

    public function testOnlyOwnersCanChangeIt(): void
    {
        $teacher = Fixtures::provider($this->pdo, ['slug' => 'demo', 'notify_email' => 'bookings@example.test']);
        $this->createUser('teacher@example.test', 'provider', $teacher);
        $this->login('teacher@example.test');

        self::assertFalse($this->admin('GET', '/api/admin/me/notifications')[1]['data']['applies']);
        self::assertSame(403, $this->admin('PUT', '/api/admin/me/notifications', ['booking_emails' => false])[0]);
        self::assertSame(['1'], array_map('strval', self::column($this->pdo, "SELECT booking_emails FROM users WHERE email = 'teacher@example.test'")));
    }

    public function testEachOwnerChoosesForThemselves(): void
    {
        $teacher = Fixtures::provider($this->pdo, ['slug' => 'demo', 'notify_email' => 'Admin@example.test']);
        $this->createUser('admin@example.test');
        $this->createUser('partner@example.test');
        $this->pdo->exec("UPDATE users SET booking_emails = 0 WHERE email = 'partner@example.test'");

        self::assertSame(['Admin@example.test'], (new PdoBookingViews($this->pdo))->staffEmailsForProvider($teacher), 'opted-in owner deduped against the booking address');
    }
}
