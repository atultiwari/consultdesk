<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Integration\Calendar;

use ConsultDesk\Calendar\GoogleConnectFailed;
use ConsultDesk\Domain\Availability\Interval;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Tests\Integration\Http\ApiTestCase;
use ConsultDesk\Tests\Integration\Support\Fixtures;
use ConsultDesk\Tests\Support\FakeGoogleApi;

/**
 * "Now" is Monday 2026-10-05 00:00 UTC; bookings are for Wednesday 7 October (IST).
 */
final class GoogleCalendarTest extends ApiTestCase
{
    private int $providerId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google = new FakeGoogleApi();
        $this->providerId = Fixtures::provider($this->pdo, ['slug' => 'demo', 'name' => 'Dr. Demo', 'notify_email' => 'provider@example.test']);
        Fixtures::service($this->pdo, $this->providerId, [
            'slug' => 'thesis',
            'title' => 'Thesis guidance',
            'payment_methods' => '["upi"]',
            'questions' => '[{"id": "goal", "label": "Goal", "type": "textarea", "required": true}]',
        ]);
        Fixtures::user($this->pdo, 'owner', email: 'owner@example.test');
        $this->adminId = Fixtures::user($this->pdo, 'admin', email: 'admin@example.test');
    }

    protected function extraEnv(): array
    {
        return [
            'GOOGLE_CLIENT_ID' => 'placeholder-client.apps.googleusercontent.com',
            'GOOGLE_CLIENT_SECRET' => 'placeholder-client-secret',
        ];
    }

    public function testConnectingStoresEncryptedTokensAndDefaultsToThePrimaryCalendar(): void
    {
        $email = $this->connect();

        self::assertSame('provider@example.test', $email);
        $row = $this->connectionRow();
        self::assertSame('active', $row['status']);
        self::assertSame('provider@example.test', $row['account_email']);
        self::assertSame(['provider@example.test'], json_decode((string) $row['busy_calendar_ids'], true));
        self::assertSame('provider@example.test', $row['target_calendar_id']);
        self::assertStringNotContainsString('refresh-1', (string) $row['refresh_token_enc']);
        self::assertSame('refresh-1', $this->services()->crypto()->decrypt((string) $row['refresh_token_enc']));
        self::assertContains('exchange:auth-code:43', $this->google()->calls, 'the PKCE verifier is sent');
    }

    public function testStatesWorkOnceAndExpire(): void
    {
        $oauth = $this->services()->googleOAuth();
        self::assertNotNull($oauth);
        $state = $this->stateFrom($oauth->start($this->providerId, 'provider@example.test'));
        $oauth->complete($state, 'auth-code');

        $this->assertConnectFails(fn() => $oauth->complete($state, 'auth-code'), 'expired');

        $stale = $this->stateFrom($oauth->start($this->providerId, 'provider@example.test'));
        $this->at('2026-10-05T00:31Z');
        $later = $this->services()->googleOAuth();
        self::assertNotNull($later);
        $this->assertConnectFails(fn() => $later->complete($stale, 'auth-code'), 'expired');
    }

    public function testRefusesConnectionsWithoutOfflineAccessOrCalendarScopes(): void
    {
        $this->google()->refreshTokenToIssue = null;
        $this->assertConnectFails(fn() => $this->connect(), 'offline');

        $this->google()->refreshTokenToIssue = 'refresh-2';
        $this->google()->scopeToIssue = 'openid email';
        $this->assertConnectFails(fn() => $this->connect(), 'calendar');
        self::assertSame(['refresh-2'], $this->google()->revoked, 'partial grants are revoked');

        $this->google()->scopeToIssue = 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy';
        $this->assertConnectFails(fn() => $this->connect(), 'calendar');
        self::assertSame([], self::column($this->pdo, 'SELECT id FROM oauth_tokens'));
    }

    public function testOnlyTheExpectedGoogleAccountCanConnect(): void
    {
        $this->google()->accountEmailToReturn = 'attacker@example.test';

        $this->assertConnectFails(fn() => $this->connect('provider@example.test'), 'provider@example.test');
        self::assertSame(['refresh-1'], $this->google()->revoked);
        self::assertSame([], self::column($this->pdo, 'SELECT id FROM oauth_tokens'));
        self::assertSame(['provider@example.test'], $this->google()->loginHints);
    }

    public function testAnExistingConnectionIsOnlyReplacedOnPurpose(): void
    {
        $this->connect();
        $this->google()->refreshTokenToIssue = 'refresh-2';

        $this->assertConnectFails(fn() => $this->connect(), 'already connected');
        self::assertSame(['refresh-2'], $this->google()->revoked);

        $this->google()->refreshTokenToIssue = 'refresh-3';
        $this->connect(replace: true);
        self::assertContains('refresh-1', $this->google()->revoked, 'the replaced grant is revoked');
        self::assertSame('refresh-3', $this->services()->crypto()->decrypt((string) $this->connectionRow()['refresh_token_enc']));
    }

    public function testFailuresAfterTheExchangeRevokeTheNewGrant(): void
    {
        $this->google()->accountEmailFails = true;

        $this->assertConnectFails(fn() => $this->connect(), 'try again');
        self::assertSame(['refresh-1'], $this->google()->revoked);
    }

    public function testAStaleRefreshCannotBreakANewConnection(): void
    {
        $this->connect();
        $staleConnection = $this->services()->googleConnections()->find($this->providerId);
        self::assertNotNull($staleConnection);

        $this->google()->refreshTokenToIssue = 'refresh-2';
        $this->connect(replace: true);

        self::assertFalse($this->services()->googleConnections()->markBroken($staleConnection, 'old grant revoked'));
        self::assertSame('active', $this->connectionRow()['status']);
    }

    public function testTheCallbackPageReportsTheOutcome(): void
    {
        $oauth = $this->services()->googleOAuth();
        self::assertNotNull($oauth);
        $state = $this->stateFrom($oauth->start($this->providerId, 'provider@example.test'));

        [$status, , $response] = $this->call('GET', "/api/google/callback?state={$state}&code=auth-code");
        self::assertSame(200, $status);
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Google Calendar connected', (string) $response->getBody());

        [$denied, , $deniedResponse] = $this->call('GET', '/api/google/callback?error=access_denied&state=x');
        self::assertSame(400, $denied);
        self::assertStringContainsString('not connected', (string) $deniedResponse->getBody());
    }

    public function testFreeBusyIsFetchedPerWeekSoArbitraryRangesCannotRunUpGoogleCalls(): void
    {
        $this->connect();

        foreach (['2026-10-06&to=2026-10-06', '2026-10-07&to=2026-10-09', '2026-10-05&to=2026-10-10'] as $range) {
            $this->call('GET', "/api/providers/demo/services/thesis/slots?from={$range}", ip: '198.51.100.' . random_int(1, 250));
        }
        self::assertSame(2, $this->google()->count('freeBusy'), 'week of 28 Sep (for the day before) and week of 5 Oct');

        $this->call('GET', '/api/providers/demo/services/thesis/slots?from=2027-06-01&to=2027-06-30', ip: '198.51.100.251');
        self::assertSame(2, $this->google()->count('freeBusy'), 'nothing beyond the booking horizon is fetched');
    }

    public function testAGoogleOutageIsRememberedForAMinute(): void
    {
        $this->connect();
        $this->google()->freeBusyDown = true;

        $this->slotStarts();
        $this->slotStarts();
        self::assertSame(1, $this->google()->count('freeBusy'), 'one failed call, then remembered');
        $this->at('2026-10-05T00:01Z');
        $this->slotStarts();
        self::assertSame(2, $this->google()->count('freeBusy'));
    }

    public function testGoogleBusyTimeHidesSlotsAndIsCachedForTwoMinutes(): void
    {
        $this->connect();
        $this->google()->busy = [Interval::fromStrings('2026-10-07T04:30Z', '2026-10-07T05:30Z')]; // 10:00–11:00 IST

        $starts = $this->slotStarts();
        self::assertNotContains('2026-10-07T04:30:00Z', $starts);
        self::assertContains('2026-10-07T06:30:00Z', $starts);
        self::assertSame(['provider@example.test'], $this->google()->freeBusyCalendars[0]);

        $calls = $this->google()->count('freeBusy');
        $this->slotStarts();
        self::assertSame($calls, $this->google()->count('freeBusy'), 'second call within 2 minutes is cached');
        $this->at('2026-10-05T00:02Z');
        $this->slotStarts();
        self::assertGreaterThan($calls, $this->google()->count('freeBusy'));
    }

    public function testBookingAGoogleBusySlotIsRefused(): void
    {
        $this->connect();
        $this->google()->busy = [Interval::fromStrings('2026-10-07T04:30Z', '2026-10-07T05:30Z')];

        [$status, $body] = $this->call('POST', '/api/bookings', $this->booking());

        self::assertSame(409, $status);
        self::assertSame('slot_unavailable', $body['error']['code']);
    }

    public function testBookingStaysOpenWhenGoogleIsDown(): void
    {
        $this->connect();
        $this->google()->freeBusyDown = true;

        self::assertContains('2026-10-07T04:30:00Z', $this->slotStarts());
        self::assertSame(201, $this->call('POST', '/api/bookings', $this->booking())[0]);
    }

    public function testExpiredAccessTokensAreRefreshedAndStored(): void
    {
        $this->connect();
        $this->at('2026-10-05T01:00Z');

        $this->slotStarts();

        self::assertSame(['refresh:refresh-1'], array_values(array_filter($this->google()->calls, static fn(string $c): bool => str_starts_with($c, 'refresh'))));
        self::assertContains('freeBusy:access-2', $this->google()->calls);
        self::assertSame('access-2', $this->services()->crypto()->decrypt((string) $this->connectionRow()['access_token_enc']));
    }

    public function testRevokedAccessMarksTheConnectionBrokenAndEmailsStaffOnce(): void
    {
        $this->connect();
        $this->google()->refreshRevoked = true;
        $this->at('2026-10-05T01:00Z');

        self::assertContains('2026-10-07T04:30:00Z', $this->slotStarts(), 'booking stays open');
        $this->slotStarts();
        $this->runCron();

        self::assertSame('broken', $this->connectionRow()['status']);
        $notices = array_values(array_filter($this->mailer->sent, static fn($m) => str_contains($m->subject, 'Google Calendar disconnected')));
        self::assertCount(1, $notices);
        self::assertSame(['provider@example.test', 'owner@example.test'], $notices[0]->to);
    }

    public function testConfirmingCreatesAnEventWithMeetAndDelaysTheCustomerEmailForTheLink(): void
    {
        $this->connect();
        $booking = $this->bookPayAndConfirm();

        $this->runCron();
        self::assertCount(1, $this->google()->events);
        $event = array_values($this->google()->events)[0];
        self::assertMatchesRegularExpression('/^cd[0-9a-f]{30}$/', (string) $event['id']);
        self::assertSame('provider@example.test', $event['calendar']);
        self::assertSame([['email' => 'asha@example.test', 'displayName' => 'Asha Placeholder']], $event['attendees']);
        self::assertSame('hangoutsMeet', $event['conferenceData']['createRequest']['conferenceSolutionKey']['type']);
        self::assertSame('2026-10-07T04:30:00Z', $event['start']['dateTime']);
        self::assertSame('Asia/Kolkata', $event['start']['timeZone']);
        self::assertStringContainsString($booking['ref'], (string) $event['description']);
        self::assertStringNotContainsString('Feedback on my thesis', (string) $event['description'], 'free-text answers stay out of the invite');

        $statement = $this->pdo->prepare('SELECT gcal_event_id, meet_url FROM bookings WHERE id = ?');
        $statement->execute([$booking['id']]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        self::assertSame($event['id'], $row['gcal_event_id']);
        self::assertStringStartsWith('https://meet.example.test/', (string) $row['meet_url']);
        self::assertSame([], $this->customerConfirmations($booking['ref']), 'held back until the Meet link exists');

        $this->at('2026-10-05T00:02Z');
        $this->runCron();
        $emails = $this->customerConfirmations($booking['ref']);
        self::assertCount(1, $emails);
        self::assertStringContainsString((string) $row['meet_url'], $emails[0]->text);
    }

    public function testEventCreationIsIdempotentAcrossRetries(): void
    {
        $this->connect();
        $booking = $this->bookPayAndConfirm();
        $eventId = $this->services()->calendarEventId($booking['id']);
        $this->google()->events[$eventId] = ['id' => $eventId, 'calendar' => 'provider@example.test'];

        $this->runCron();

        self::assertContains("get:provider@example.test:{$eventId}", $this->google()->calls);
        self::assertSame($eventId, self::column($this->pdo, "SELECT gcal_event_id FROM bookings WHERE id = {$booking['id']}")[0] ?? null);
    }

    public function testAPendingMeetLinkIsFetchedOnALaterTry(): void
    {
        $this->connect();
        $this->google()->meetPending = true;
        $booking = $this->bookPayAndConfirm();

        $this->runCron();
        self::assertSame(['1', '0'], self::column($this->pdo, "SELECT meet_url IS NULL FROM bookings WHERE id = {$booking['id']} UNION ALL SELECT gcal_event_id IS NULL FROM bookings WHERE id = {$booking['id']}"), 'event linked, Meet link still pending');

        $this->google()->meetPending = false;
        $this->at('2026-10-05T00:02Z');
        $this->runCron();
        self::assertStringStartsWith('https://meet.example.test/', (string) (self::column($this->pdo, "SELECT meet_url FROM bookings WHERE id = {$booking['id']}")[0] ?? ''));
    }

    public function testABookingCancelledWhileItsEventIsCreatedLosesTheEvent(): void
    {
        $this->connect();
        $booking = $this->bookPayAndConfirm();
        $this->google()->duringInsert = function () use ($booking): void {
            $this->services()->bookingService()->cancel($booking['id'], Actor::user($this->adminId));
        };

        $this->runCron();
        $this->runCron();

        self::assertSame([], $this->google()->events, 'the event created during cancellation was removed');
        self::assertSame('1', self::column($this->pdo, "SELECT gcal_event_id IS NULL FROM bookings WHERE id = {$booking['id']}")[0] ?? null);
    }

    public function testAnEventDeletedByHandIsRestoredRatherThanLinked(): void
    {
        $this->connect();
        $booking = $this->bookPayAndConfirm();
        $eventId = $this->services()->calendarEventId($booking['id']);
        $this->google()->events[$eventId] = ['id' => $eventId, 'status' => 'cancelled', 'calendar' => 'provider@example.test'];

        $this->runCron();

        self::assertContains("update:provider@example.test:{$eventId}", $this->google()->calls);
        self::assertSame('confirmed', $this->google()->events[$eventId]['status'] ?? null);
    }

    public function testCancellingDeletesTheEventAndNotifiesAttendees(): void
    {
        $this->connect();
        $booking = $this->bookPayAndConfirm();
        $this->runCron();
        $eventId = (string) array_key_first($this->google()->events);

        $this->services()->bookingService()->cancel($booking['id'], Actor::user($this->adminId));
        $this->runCron();

        self::assertSame([['provider@example.test', $eventId]], $this->google()->deleted);
        self::assertSame('1', self::column($this->pdo, "SELECT gcal_event_id IS NULL FROM bookings WHERE id = {$booking['id']}")[0] ?? null);
    }

    public function testProvidersWithoutGoogleAreUnaffected(): void
    {
        $booking = $this->bookPayAndConfirm();
        $this->runCron();

        self::assertSame([], $this->google()->events);
        self::assertCount(1, $this->customerConfirmations($booking['ref']), 'sent straight away');
        self::assertSame(0, $this->google()->count('freeBusy'));
    }

    public function testDisconnectingRevokesAndForgetsTheTokens(): void
    {
        $this->connect();
        $calendar = $this->services()->googleCalendar();
        self::assertNotNull($calendar);

        $calendar->disconnect($this->providerId);

        self::assertSame(['refresh-1'], $this->google()->revoked);
        self::assertSame([], self::column($this->pdo, 'SELECT id FROM oauth_tokens'));
        self::assertFalse($calendar->isConnected($this->providerId));
    }

    private function connect(string $expectedEmail = 'provider@example.test', bool $replace = false): string
    {
        $oauth = $this->services()->googleOAuth();
        self::assertNotNull($oauth);

        return $oauth->complete($this->stateFrom($oauth->start($this->providerId, $expectedEmail, $replace)), 'auth-code');
    }

    private function stateFrom(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return is_string($query['state'] ?? null) ? $query['state'] : '';
    }

    private function assertConnectFails(callable $action, string $messagePart): void
    {
        try {
            $action();
            self::fail('Expected the connection to fail.');
        } catch (GoogleConnectFailed $e) {
            self::assertStringContainsString($messagePart, strtolower($e->getMessage()));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionRow(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM oauth_tokens WHERE provider_id = ?');
        $statement->execute([$this->providerId]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function slotStarts(): array
    {
        [$status, $body] = $this->call('GET', '/api/providers/demo/services/thesis/slots?from=2026-10-07&to=2026-10-07', ip: '198.51.100.' . random_int(1, 250));
        self::assertSame(200, $status);

        return array_column($body['data']['slots'], 'start');
    }

    /**
     * @return array{id: int, ref: string}
     */
    private function bookPayAndConfirm(): array
    {
        [$status, $created] = $this->call('POST', '/api/bookings', $this->booking());
        self::assertSame(201, $status);
        $ref = $created['data']['ref'];
        $this->call('POST', "/api/bookings/{$ref}/utr", ['token' => $created['data']['token'], 'utr' => '412345678901']);
        $id = (int) (self::column($this->pdo, 'SELECT id FROM bookings WHERE ref = :ref', ['ref' => $ref])[0] ?? 0);
        $this->services()->bookingService()->confirm($id, Actor::user($this->adminId));

        return ['id' => $id, 'ref' => $ref];
    }

    /**
     * @return list<\ConsultDesk\Notify\Mail\EmailMessage>
     */
    private function customerConfirmations(string $ref): array
    {
        return array_values(array_filter(
            $this->mailer->sent,
            static fn($m): bool => $m->to === ['asha@example.test'] && str_starts_with($m->subject, 'Confirmed: ') && str_contains($m->subject, $ref),
        ));
    }

    private function runCron(): void
    {
        $this->services()->cronRunner()->run();
    }

    private function google(): FakeGoogleApi
    {
        self::assertNotNull($this->google);

        return $this->google;
    }

    /**
     * @return array<string, mixed>
     */
    private function booking(): array
    {
        return [
            'provider' => 'demo',
            'service' => 'thesis',
            'start' => '2026-10-07T04:30:00Z',
            'customer' => ['name' => 'Asha Placeholder', 'email' => 'asha@example.test', 'phone' => '+910000000000', 'timezone' => 'Asia/Kolkata'],
            'answers' => ['goal' => 'Feedback on my thesis'],
        ];
    }
}
