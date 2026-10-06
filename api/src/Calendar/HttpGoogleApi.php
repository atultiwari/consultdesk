<?php

declare(strict_types=1);

namespace ConsultDesk\Calendar;

use ConsultDesk\Domain\Availability\Interval;
use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

final class HttpGoogleApi implements GoogleApi
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const CALENDAR_URL = 'https://www.googleapis.com/calendar/v3';
    /** Narrowest scopes that cover events, free/busy and choosing calendars. */
    public const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.freebusy',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
    ];
    private const RFC3339_UTC = 'Y-m-d\TH:i:s\Z';

    public function __construct(
        private readonly string $clientId,
        #[\SensitiveParameter]
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly ClientInterface $http,
    ) {}

    public function authorizationUrl(string $state, string $codeChallenge, string $loginHint): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'login_hint' => $loginHint,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $codeVerifier): GoogleTokens
    {
        return $this->tokens([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $codeVerifier,
        ]);
    }

    public function refresh(string $refreshToken): GoogleTokens
    {
        return $this->tokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
    }

    public function revoke(string $token): void
    {
        $response = $this->send('POST', self::REVOKE_URL, ['form_params' => ['token' => $token]], 'revoke');
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 400) {
            throw $this->error($response, 'revoke');
        }
    }

    public function accountEmail(string $accessToken): ?string
    {
        $info = $this->json('GET', self::USERINFO_URL, $accessToken, [], 'userinfo');
        $email = $info['email'] ?? null;

        return is_string($email) ? strtolower($email) : null;
    }

    public function calendars(string $accessToken): array
    {
        $list = $this->json('GET', self::CALENDAR_URL . '/users/me/calendarList', $accessToken, ['query' => ['minAccessRole' => 'freeBusyReader']], 'calendarList');
        $items = is_array($list['items'] ?? null) ? $list['items'] : [];

        $calendars = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $calendars[] = new GoogleCalendarEntry(
                    $item['id'],
                    is_string($item['summary'] ?? null) ? $item['summary'] : $item['id'],
                    ($item['primary'] ?? false) === true,
                    in_array($item['accessRole'] ?? '', ['owner', 'writer'], true),
                );
            }
        }

        return $calendars;
    }

    public function freeBusy(string $accessToken, array $calendarIds, Interval $range): array
    {
        // Short timeouts: free/busy is on the booking path, and a slow Google must not hold it up.
        $result = $this->json('POST', self::CALENDAR_URL . '/freeBusy', $accessToken, ['timeout' => 4, 'connect_timeout' => 2, 'json' => [
            'timeMin' => $range->start->format(self::RFC3339_UTC),
            'timeMax' => $range->end->format(self::RFC3339_UTC),
            'items' => array_map(static fn(string $id): array => ['id' => $id], $calendarIds),
        ]], 'freeBusy');

        $busy = [];
        foreach (is_array($result['calendars'] ?? null) ? $result['calendars'] : [] as $calendar) {
            // A calendar with errors (e.g. notFound after being deleted) simply contributes no busy time.
            foreach (is_array($calendar) && is_array($calendar['busy'] ?? null) ? $calendar['busy'] : [] as $period) {
                if (is_array($period) && is_string($period['start'] ?? null) && is_string($period['end'] ?? null)) {
                    $start = new DateTimeImmutable($period['start']);
                    $end = new DateTimeImmutable($period['end']);
                    if ($end > $start) {
                        $busy[] = new Interval($start, $end);
                    }
                }
            }
        }

        return $busy;
    }

    public function insertEvent(string $accessToken, string $calendarId, array $event): GoogleEvent
    {
        return self::event($this->json(
            'POST',
            self::eventsUrl($calendarId),
            $accessToken,
            ['json' => $event, 'query' => ['conferenceDataVersion' => 1, 'sendUpdates' => 'all']],
            'events.insert',
        ));
    }

    public function getEvent(string $accessToken, string $calendarId, string $eventId): GoogleEvent
    {
        return self::event($this->json('GET', self::eventsUrl($calendarId) . '/' . rawurlencode($eventId), $accessToken, [], 'events.get'));
    }

    public function updateEvent(string $accessToken, string $calendarId, string $eventId, array $event): GoogleEvent
    {
        return self::event($this->json(
            'PUT',
            self::eventsUrl($calendarId) . '/' . rawurlencode($eventId),
            $accessToken,
            ['json' => $event, 'query' => ['conferenceDataVersion' => 1, 'sendUpdates' => 'all']],
            'events.update',
        ));
    }

    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        $response = $this->send('DELETE', self::eventsUrl($calendarId) . '/' . rawurlencode($eventId), [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'query' => ['sendUpdates' => 'all'],
        ], 'events.delete');
        if ($response->getStatusCode() >= 400 && !in_array($response->getStatusCode(), [404, 410], true)) {
            throw $this->error($response, 'events.delete');
        }
    }

    /**
     * @param array<string, string> $form
     */
    private function tokens(array $form): GoogleTokens
    {
        $response = $this->send('POST', self::TOKEN_URL, ['form_params' => $form], 'token');
        $body = self::decode($response);
        if ($response->getStatusCode() !== 200 || !is_string($body['access_token'] ?? null)) {
            throw $this->error($response, 'token');
        }

        return new GoogleTokens(
            $body['access_token'],
            is_string($body['refresh_token'] ?? null) ? $body['refresh_token'] : null,
            is_int($body['expires_in'] ?? null) ? $body['expires_in'] : 3600,
            is_string($body['scope'] ?? null) ? $body['scope'] : '',
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $url, string $accessToken, array $options, string $label): array
    {
        $options['headers'] = ['Authorization' => 'Bearer ' . $accessToken];
        $response = $this->send($method, $url, $options, $label);
        if ($response->getStatusCode() >= 300) {
            throw $this->error($response, $label);
        }

        return self::decode($response);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options, string $label): ResponseInterface
    {
        try {
            return $this->http->request($method, $url, $options + ['http_errors' => false, 'timeout' => 10, 'connect_timeout' => 3]);
        } catch (GuzzleException) {
            // Exception messages can echo request details; they are dropped.
            throw new GoogleApiError(sprintf('Google API unreachable (%s).', $label));
        }
    }

    private function error(ResponseInterface $response, string $label): GoogleApiError
    {
        $status = $response->getStatusCode();
        $body = self::decode($response);
        $code = $body['error'] ?? null;
        $message = match (true) {
            is_string($code) => $code . (is_string($body['error_description'] ?? null) ? ': ' . $body['error_description'] : ''),
            is_array($code) && is_string($code['message'] ?? null) => $code['message'],
            default => 'HTTP ' . $status,
        };
        $message = str_replace($this->clientSecret, '***', $message);
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $message) ?? '';

        return new GoogleApiError(
            sprintf('Google %s failed: %s', $label, mb_substr($clean, 0, 300)),
            $status,
            permanent: $status >= 400 && $status < 500 && $status !== 429,
            invalidGrant: $code === 'invalid_grant',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function event(array $body): GoogleEvent
    {
        $id = $body['id'] ?? null;
        if (!is_string($id)) {
            throw new GoogleApiError('Google returned an event without an id.');
        }
        $meet = is_string($body['hangoutLink'] ?? null) ? $body['hangoutLink'] : null;
        foreach (is_array($body['conferenceData']['entryPoints'] ?? null) ? $body['conferenceData']['entryPoints'] : [] as $entry) {
            if ($meet === null && is_array($entry) && ($entry['entryPointType'] ?? null) === 'video' && is_string($entry['uri'] ?? null)) {
                $meet = $entry['uri'];
            }
        }

        return new GoogleEvent($id, $meet, ($body['status'] ?? null) === 'cancelled');
    }

    private static function eventsUrl(string $calendarId): string
    {
        return self::CALENDAR_URL . '/calendars/' . rawurlencode($calendarId) . '/events';
    }
}
