<?php

declare(strict_types=1);

namespace ConsultDesk\Tests\Unit\Calendar;

use ArrayObject;
use ConsultDesk\Calendar\GoogleApiError;
use ConsultDesk\Calendar\HttpGoogleApi;
use ConsultDesk\Domain\Availability\Interval;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class HttpGoogleApiTest extends TestCase
{
    private const CLIENT_ID = 'placeholder-client.apps.googleusercontent.com';
    private const SECRET = 'placeholder-client-secret';
    private const REDIRECT = 'https://book.example.test/api/google/callback';

    /** Filled by Guzzle's history middleware. */
    private mixed $history = null;

    public function testAuthorizationUrlAsksForOfflineAccessWithPkce(): void
    {
        $url = $this->api([])->authorizationUrl('state-123', 'challenge-abc', 'provider@example.test');

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        self::assertSame(self::CLIENT_ID, $q['client_id']);
        self::assertSame(self::REDIRECT, $q['redirect_uri']);
        self::assertSame('code', $q['response_type']);
        self::assertSame('offline', $q['access_type']);
        self::assertSame('consent', $q['prompt']);
        self::assertSame('state-123', $q['state']);
        self::assertSame('challenge-abc', $q['code_challenge']);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertSame('provider@example.test', $q['login_hint']);
        self::assertArrayNotHasKey('include_granted_scopes', $q, 'earlier grants must not satisfy the scope check');
        self::assertSame(
            'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy https://www.googleapis.com/auth/calendar.calendarlist.readonly',
            $q['scope'],
        );
    }

    public function testExchangesTheCodeWithTheVerifier(): void
    {
        $api = $this->api([new Response(200, [], '{"access_token":"at","expires_in":3599,"refresh_token":"rt","scope":"x","token_type":"Bearer"}')]);

        $tokens = $api->exchangeCode('auth-code', 'verifier-xyz');

        self::assertSame('at', $tokens->accessToken);
        self::assertSame('rt', $tokens->refreshToken);
        self::assertSame(3599, $tokens->expiresIn);
        parse_str((string) $this->request(0)->getBody(), $form);
        self::assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'auth-code',
            'redirect_uri' => self::REDIRECT,
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::SECRET,
            'code_verifier' => 'verifier-xyz',
        ], $form);
    }

    public function testInvalidGrantIsReportedAsRevokedAccess(): void
    {
        $api = $this->api([new Response(400, [], '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}')]);

        try {
            $api->refresh('rt');
            self::fail('Expected an error.');
        } catch (GoogleApiError $e) {
            self::assertTrue($e->invalidGrant);
            self::assertTrue($e->permanent);
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
        }
    }

    public function testFreeBusyReturnsUtcIntervalsAcrossCalendars(): void
    {
        $api = $this->api([new Response(200, [], (string) json_encode(['calendars' => [
            'primary@example.test' => ['busy' => [['start' => '2026-10-07T10:00:00+05:30', 'end' => '2026-10-07T11:00:00+05:30']]],
            'gone@example.test' => ['errors' => [['domain' => 'global', 'reason' => 'notFound']], 'busy' => []],
        ]]))]);

        $busy = $api->freeBusy('at', ['primary@example.test', 'gone@example.test'], Interval::fromStrings('2026-10-07T00:00Z', '2026-10-08T00:00Z'));

        self::assertCount(1, $busy);
        self::assertSame('2026-10-07T04:30:00+00:00', $busy[0]->start->format(DATE_ATOM));
        self::assertSame('Bearer at', $this->request(0)->getHeaderLine('Authorization'));
        self::assertSame([
            'timeMin' => '2026-10-07T00:00:00Z',
            'timeMax' => '2026-10-08T00:00:00Z',
            'items' => [['id' => 'primary@example.test'], ['id' => 'gone@example.test']],
        ], json_decode((string) $this->request(0)->getBody(), true));
    }

    public function testInsertsEventsWithMeetAndInvitesAndReadsTheLink(): void
    {
        $api = $this->api([new Response(200, [], '{"id":"cdabc","hangoutLink":"https://meet.google.com/abc-defg-hij"}')]);

        $event = $api->insertEvent('at', 'primary', ['id' => 'cdabc', 'summary' => 'x']);

        self::assertSame('cdabc', $event->id);
        self::assertSame('https://meet.google.com/abc-defg-hij', $event->meetUrl);
        $request = $this->request(0);
        self::assertSame('/calendar/v3/calendars/primary/events', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        self::assertSame(['conferenceDataVersion' => '1', 'sendUpdates' => 'all'], $q);
    }

    public function testReadsEventStatusAndCanRestoreAnEvent(): void
    {
        $api = $this->api([
            new Response(200, [], '{"id":"cdabc","status":"cancelled"}'),
            new Response(200, [], '{"id":"cdabc","status":"confirmed","hangoutLink":"https://meet.google.com/x"}'),
        ]);

        self::assertTrue($api->getEvent('at', 'primary', 'cdabc')->cancelled);
        $restored = $api->updateEvent('at', 'primary', 'cdabc', ['id' => 'cdabc', 'status' => 'confirmed']);

        self::assertFalse($restored->cancelled);
        $request = $this->request(1);
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/calendar/v3/calendars/primary/events/cdabc', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        self::assertSame(['conferenceDataVersion' => '1', 'sendUpdates' => 'all'], $q);
    }

    public function testDuplicateEventIdsAreReportedAsConflicts(): void
    {
        $api = $this->api([new Response(409, [], '{"error":{"code":409,"message":"The requested identifier already exists."}}')]);

        try {
            $api->insertEvent('at', 'primary', ['id' => 'cdabc']);
            self::fail('Expected an error.');
        } catch (GoogleApiError $e) {
            self::assertSame(409, $e->status);
        }
    }

    public function testDeletingAnEventThatIsAlreadyGoneIsFine(): void
    {
        $api = $this->api([new Response(410, [], '{"error":{"code":410,"message":"Resource has been deleted"}}')]);

        $api->deleteEvent('at', 'primary', 'cdabc');

        self::assertSame('DELETE', $this->request(0)->getMethod());
        parse_str($this->request(0)->getUri()->getQuery(), $q);
        self::assertSame('all', $q['sendUpdates']);
    }

    public function testNetworkFailuresAreRetryableAndQuiet(): void
    {
        $api = $this->api([new ConnectException('cURL error 7 for https://oauth2.googleapis.com/token', new Request('POST', 'https://oauth2.googleapis.com/token'))]);

        try {
            $api->refresh('rt-secret');
            self::fail('Expected an error.');
        } catch (GoogleApiError $e) {
            self::assertFalse($e->permanent);
            self::assertStringNotContainsString('rt-secret', $e->getMessage());
        }
    }

    public function testReadsTheAccountEmailAndCalendarList(): void
    {
        $api = $this->api([
            new Response(200, [], '{"email":"Provider@Example.test","email_verified":true}'),
            new Response(200, [], '{"items":[{"id":"provider@example.test","summary":"Provider","primary":true,"accessRole":"owner"},{"id":"team@group.calendar.google.com","summary":"Team","accessRole":"reader"}]}'),
        ]);

        self::assertSame('provider@example.test', $api->accountEmail('at'));
        $calendars = $api->calendars('at');
        self::assertSame(['provider@example.test', 'team@group.calendar.google.com'], array_map(static fn($c) => $c->id, $calendars));
        self::assertTrue($calendars[0]->primary);
        self::assertFalse($calendars[1]->writable);
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function api(array $responses): HttpGoogleApi
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $history = new ArrayObject();
        $stack->push(Middleware::history($history));
        $this->history = $history;

        return new HttpGoogleApi(self::CLIENT_ID, self::SECRET, self::REDIRECT, new Client(['handler' => $stack]));
    }

    private function request(int $index): RequestInterface
    {
        $entry = $this->history instanceof ArrayObject ? ($this->history[$index] ?? null) : null;
        $request = is_array($entry) ? ($entry['request'] ?? null) : null;
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }
}
