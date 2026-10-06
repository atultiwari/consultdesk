<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\TelegramLinks;
use ConsultDesk\Calendar\CalendarDisconnected;
use ConsultDesk\Calendar\GoogleApiError;
use ConsultDesk\Calendar\GoogleCalendar;
use ConsultDesk\Calendar\GoogleCalendarEntry;
use ConsultDesk\Calendar\GoogleConnections;
use ConsultDesk\Calendar\GoogleOAuth;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Telegram alerts and Google Calendar, set up from the panel instead of bin/telegram.php and
 * bin/google.php. A provider manages their own; staff manage anyone's.
 */
final class AdminIntegrationActions
{
    public function __construct(
        private readonly ProviderSettings $providers,
        private readonly TelegramLinks $telegram,
        private readonly GoogleConnections $connections,
        private readonly ?GoogleOAuth $oauth,
        private readonly ?GoogleCalendar $calendar,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function status(Request $request, Response $response, array $args): Response
    {
        $id = $this->providerId($request, $args);
        $connection = $this->connections->find($id);
        $google = ['configured' => $this->oauth !== null, 'connected' => $connection !== null];
        if ($connection !== null) {
            $google = [
                ...$google,
                'active' => $connection->active,
                'account_email' => $connection->accountEmail,
                'busy_calendar_ids' => $connection->busyCalendarIds,
                'target_calendar_id' => $connection->targetCalendarId,
            ];
        }

        return JsonResponse::success($response, [
            'telegram' => ['configured' => $this->telegram->configured(), 'linked' => $this->telegram->providerLinked($id)],
            'google' => $google,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function telegramLink(Request $request, Response $response, array $args): Response
    {
        $id = $this->providerId($request, $args);
        $url = $this->telegram->link('provider', $id) ?? throw self::notConfigured('Telegram');
        $this->audit->record($this->actor($request), 'admin.telegram_link_created', 'provider', $id);

        return JsonResponse::success($response, ['url' => $url]);
    }

    /**
     * @param array<string, string> $args
     */
    public function telegramUnlink(Request $request, Response $response, array $args): Response
    {
        $id = $this->providerId($request, $args);
        $this->telegram->unlinkProvider($id);
        $this->audit->record($this->actor($request), 'admin.telegram_unlinked', 'provider', $id);

        return JsonResponse::success($response, ['ok' => true]);
    }

    public function myTelegram(Request $request, Response $response): Response
    {
        $user = AdminScope::user($request);

        return JsonResponse::success($response, ['configured' => $this->telegram->configured(), 'linked' => $this->telegram->userLinked($user->id)]);
    }

    public function myTelegramLink(Request $request, Response $response): Response
    {
        $user = AdminScope::user($request);
        $url = $this->telegram->link('user', $user->id) ?? throw self::notConfigured('Telegram');
        $this->audit->record(Actor::user($user->id), 'admin.telegram_link_created', 'user', $user->id);

        return JsonResponse::success($response, ['url' => $url]);
    }

    public function myTelegramUnlink(Request $request, Response $response): Response
    {
        $user = AdminScope::user($request);
        $this->telegram->unlinkUser($user->id);
        $this->audit->record(Actor::user($user->id), 'admin.telegram_unlinked', 'user', $user->id);

        return JsonResponse::success($response, ['ok' => true]);
    }

    /**
     * A consent link for the provider's Google account; it works once, for 30 minutes, and only
     * for that account.
     *
     * @param array<string, string> $args
     */
    public function googleConnect(Request $request, Response $response, array $args): Response
    {
        $id = $this->providerId($request, $args);
        $oauth = $this->oauth ?? throw self::notConfigured('Google Calendar');
        $input = new Input(JsonInput::decode($request));
        $email = $input->email('email');
        $replace = $input->bool('replace') ?? false;
        $input->assertValid();

        $url = $oauth->start($id, strtolower((string) $email), $replace);
        $this->audit->record($this->actor($request), 'admin.google_link_created', 'provider', $id, ['email' => strtolower((string) $email)]);

        return JsonResponse::success($response, ['url' => $url]);
    }

    /**
     * @param array<string, string> $args
     */
    public function googleCalendars(Request $request, Response $response, array $args): Response
    {
        $id = $this->providerId($request, $args);

        return JsonResponse::success($response, array_map(static fn(GoogleCalendarEntry $c): array => [
            'id' => $c->id,
            'summary' => $c->summary,
            'primary' => $c->primary,
            'writable' => $c->writable,
        ], $this->calendarsOf($id)));
    }

    /**
     * @param array<string, string> $args
     */
    public function googleSetCalendars(Request $request, Response $response, array $args): Response
    {
        $id = $this->providerId($request, $args);
        $input = new Input(JsonInput::decode($request));
        $busy = $input->list('busy', required: true, max: 20) ?? [];
        $target = $input->string('target', max: 255);
        $known = [];
        foreach ($this->calendarsOf($id) as $entry) {
            $known[$entry->id] = $entry->writable;
        }
        if ($busy === [] || array_diff($busy, array_keys($known)) !== []) {
            $input->reject('busy', 'Choose calendars from your Google account.');
        }
        if ($target !== null && ($known[$target] ?? false) !== true) {
            $input->reject('target', 'Events need a calendar you can edit.');
        }
        $input->assertValid();

        $this->connections->setCalendars($id, array_map('strval', $busy), (string) $target);
        $this->audit->record($this->actor($request), 'admin.google_calendars_set', 'provider', $id);

        return $this->status($request, $response, $args);
    }

    /**
     * @param array<string, string> $args
     */
    public function googleDisconnect(Request $request, Response $response, array $args): Response
    {
        $id = $this->providerId($request, $args);
        $this->connectedCalendar()->disconnect($id);
        $this->audit->record($this->actor($request), 'admin.google_disconnected', 'provider', $id);

        return $this->status($request, $response, $args);
    }

    /**
     * @return list<GoogleCalendarEntry>
     */
    private function calendarsOf(int $providerId): array
    {
        try {
            return $this->connectedCalendar()->calendars($providerId);
        } catch (CalendarDisconnected) {
            throw new ApiException(409, 'calendar_disconnected', 'Google Calendar is not connected. Connect it first.');
        } catch (GoogleApiError) {
            throw new ApiException(502, 'google_unavailable', 'Google did not answer. Try again in a minute.');
        }
    }

    private function connectedCalendar(): GoogleCalendar
    {
        return $this->calendar ?? throw self::notConfigured('Google Calendar');
    }

    /**
     * @param array<string, string> $args
     */
    private function providerId(Request $request, array $args): int
    {
        return (int) AdminScope::provider($this->providers, $request, (int) ($args['id'] ?? 0))['id'];
    }

    private function actor(Request $request): Actor
    {
        return Actor::user(AdminScope::user($request)->id);
    }

    private static function notConfigured(string $what): ApiException
    {
        return new ApiException(409, 'not_configured', sprintf('%s is not set up on this server yet. See docs/INSTALL.md.', $what));
    }
}
