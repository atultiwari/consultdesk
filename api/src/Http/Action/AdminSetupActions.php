<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\SiteSetup;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Seed\ServiceTemplates;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The owner's "Set up your site" wizard (also reached after the installer): one teacher or
 * several, the teacher's profile, and starter sessions.
 */
final class AdminSetupActions
{
    private const MAX_SESSIONS = 20;

    public function __construct(
        private readonly SiteSetup $setup,
        private readonly AuditLog $audit,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        AdminScope::owner($request);

        return JsonResponse::success($response, $this->state());
    }

    public function setMode(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $input = new Input(JsonInput::decode($request));
        $mode = $input->oneOf('mode', [SiteSetup::SINGLE, SiteSetup::MULTI]);
        $input->assertValid();
        if ($mode === SiteSetup::SINGLE && $this->setup->activeProviders() > 1) {
            throw new ApiException(409, 'several_teachers', 'This site already has more than one teacher taking bookings. Hide the others first.');
        }

        $this->setup->setMode((string) $mode);
        $this->audit->record(Actor::user($owner->id), 'admin.site_mode_changed', 'settings', null, ['mode' => $mode]);

        return JsonResponse::success($response, $this->state());
    }

    public function saveTeacher(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $input = new Input(JsonInput::decode($request));
        $values = [
            'name' => $input->personName('name'),
            'title' => $input->string('title', required: false, max: 160),
            'bio' => $input->string('bio', required: false, max: 5000),
            'timezone' => $input->timezone('timezone'),
            'notify_email' => ($email = $input->email('notify_email', required: false)) === null ? null : strtolower($email),
            'whatsapp' => $input->phone('whatsapp', required: false),
            'upi_vpa' => $input->string('upi_vpa', required: false, max: 100),
            'upi_payee_name' => $input->string('upi_payee_name', required: false, max: 100),
        ];
        if ($values['upi_vpa'] !== null && preg_match(AdminProviderActions::UPI_VPA, (string) $values['upi_vpa']) !== 1) {
            $input->reject('upi_vpa', 'Enter a UPI ID like name@bank.');
        }
        $input->assertValid();

        $id = $this->setup->saveTeacher($values);
        $this->audit->record(Actor::user($owner->id), 'admin.setup_teacher_saved', 'provider', $id);

        return JsonResponse::success($response, $this->state());
    }

    public function addSessions(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $teacher = $this->setup->teacher() ?? throw new ApiException(409, 'teacher_first', 'Add the teacher’s details first.');
        $input = new Input(JsonInput::decode($request));
        $raw = $input->list('sessions', required: true, max: self::MAX_SESSIONS) ?? [];
        $choices = [];
        foreach ($raw as $i => $item) {
            $item = is_array($item) ? new Input($item, "sessions.{$i}.") : null;
            $template = $item === null ? null : ServiceTemplates::find((string) $item->string('key', max: 120));
            if ($item === null || $template === null) {
                $input->reject("sessions.{$i}", 'Choose one of the starter sessions.');
                continue;
            }
            $choices[] = [
                'template' => $template,
                'title' => $item->string('title', required: false, max: 160),
                'duration_min' => $item->int('duration_min', required: false, min: 5, max: 1440),
                'price_minor' => $item->int('price_minor', required: false, min: 0, max: 100_000_000),
            ];
            foreach ($item->errors() as $field => $message) {
                $input->reject($field, $message);
            }
        }
        $input->assertValid();

        $added = $this->setup->addSessions((int) $teacher['id'], $choices);
        $this->audit->record(Actor::user($owner->id), 'admin.setup_sessions_added', 'provider', (int) $teacher['id'], ['sessions' => $added]);

        return JsonResponse::success($response, $this->state());
    }

    public function complete(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        if ($this->setup->stored()['mode'] === null || $this->setup->teacher() === null) {
            throw new ApiException(409, 'not_ready', 'Choose how the site is used and add a teacher first.');
        }
        $this->setup->complete();
        $this->audit->record(Actor::user($owner->id), 'admin.setup_completed', 'settings', null);

        return JsonResponse::success($response, $this->state());
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $stored = $this->setup->stored();

        return [
            'mode' => $stored['mode'],
            'completed' => $stored['completed'],
            'provider' => $this->setup->teacher(),
            'template_sets' => SiteSetup::templateSets(),
        ];
    }
}
