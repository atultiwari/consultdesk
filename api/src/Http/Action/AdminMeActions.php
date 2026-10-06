<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\AdminUsers;
use ConsultDesk\Admin\Passwords;
use ConsultDesk\Admin\Sessions;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Your own account: display name and password. Open to every role.
 */
final class AdminMeActions
{
    public function __construct(
        private readonly AdminUsers $users,
        private readonly Passwords $passwords,
        private readonly Sessions $sessions,
        private readonly AuditLog $audit,
    ) {}

    public function rename(Request $request, Response $response): Response
    {
        $session = AdminAuthActions::session($request);
        $input = new Input(JsonInput::decode($request));
        $name = $input->has('name') ? $input->string('name', required: false, max: 120) : $session->user->name;
        if ($name !== null && preg_match('#://|[<>]|[\x00-\x1F\x7F]#', $name) === 1) {
            $input->reject('name', 'Enter just your name.');
        }
        $input->assertValid();

        $this->users->setName($session->user->id, $name);
        $user = $this->users->find($session->user->id);

        return JsonResponse::success($response, ['user' => $user?->toArray(), 'csrf_token' => $session->csrfToken]);
    }

    /**
     * Changes your password after checking the current one, and signs out your other sessions.
     */
    public function changePassword(Request $request, Response $response): Response
    {
        $session = AdminAuthActions::session($request);
        $input = new Input(JsonInput::decode($request));
        $current = $input->secret('current_password', max: Passwords::MAX_LENGTH);
        $new = $input->secret('new_password', max: Passwords::MAX_LENGTH);
        if ($new !== null && !Passwords::acceptable($new)) {
            $input->reject('new_password', sprintf('Use at least %d characters.', Passwords::MIN_LENGTH));
        }
        $input->assertValid();

        $found = $this->users->findForLogin($session->user->email);
        if (!$this->passwords->verify((string) $current, $found[1] ?? null)) {
            $input->reject('current_password', 'That isn’t your current password.');
            $input->assertValid();
        }

        $this->users->setPasswordHash($session->user->id, $this->passwords->hash((string) $new));
        $this->sessions->endOthers($session->user->id, $session->token);
        $this->audit->record(Actor::user($session->user->id), 'admin.password_changed', 'user', $session->user->id);

        return JsonResponse::success($response, ['ok' => true]);
    }
}
