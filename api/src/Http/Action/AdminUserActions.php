<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\AdminUsers;
use ConsultDesk\Admin\PasswordResets;
use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\Role;
use ConsultDesk\Admin\Sessions;
use ConsultDesk\Admin\UserDirectory;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The owner's Users panel: invite people, change their role, disable them. New users get an email
 * link to choose their own password; the owner never sees or sets it.
 */
final class AdminUserActions
{
    public function __construct(
        private readonly UserDirectory $directory,
        private readonly AdminUsers $users,
        private readonly ProviderSettings $providers,
        private readonly PasswordResets $resets,
        private readonly Sessions $sessions,
        private readonly AuditLog $audit,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        AdminScope::owner($request);

        return JsonResponse::success($response, $this->directory->all());
    }

    public function create(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $input = new Input(JsonInput::decode($request));
        $email = $input->email('email');
        if ($email !== null && $this->directory->emailTaken($email)) {
            $input->reject('email', 'Someone already has an account with this email.');
        }
        $name = $input->has('name') ? $input->personName('name') : null;
        [$role, $providerId] = $this->roleAndProvider($input);
        $input->assertValid();

        $id = $this->users->create((string) $email, null, $role, $providerId, $name);
        $this->resets->invite($id);
        $this->directory->markInvited($id);
        $this->audit->record(Actor::user($owner->id), 'admin.user_invited', 'user', $id, ['email' => strtolower((string) $email), 'role' => $role->value]);

        return JsonResponse::success($response, $this->directory->find($id), status: 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $owner = AdminScope::owner($request);
        $user = $this->directory->find((int) ($args['id'] ?? 0)) ?? throw ApiException::notFound();
        $input = new Input(JsonInput::decode($request));
        $self = $user['id'] === $owner->id;

        $name = $input->has('name') ? $input->string('name', required: false, max: 120) : $user['name'];
        [$role, $providerId] = $this->roleAndProvider($input, Role::from((string) $user['role']), $user['provider']['id'] ?? null);
        if ($self && $role !== Role::Owner) {
            $input->reject('role', 'You can’t change your own role. Ask another owner.');
        }
        $disabled = $input->has('disabled') ? $input->bool('disabled', required: true) : null;
        if ($self && $disabled === true) {
            $input->reject('disabled', 'You can’t disable your own account.');
        }
        $input->assertValid();

        $this->directory->update($user['id'], is_string($name) ? $name : null, $role, $providerId);
        $actor = Actor::user($owner->id);
        if ($role->value !== $user['role'] || $providerId !== ($user['provider']['id'] ?? null)) {
            $this->sessions->endAll($user['id']);
            $this->audit->record($actor, 'admin.user_role_changed', 'user', $user['id'], ['from' => $user['role'], 'to' => $role->value, 'provider_id' => $providerId]);
        }
        if ($disabled !== null && $disabled !== ($user['status'] === 'disabled')) {
            $this->directory->setDisabled($user['id'], $disabled);
            if ($disabled) {
                $this->sessions->endAll($user['id']);
            }
            $this->audit->record($actor, $disabled ? 'admin.user_disabled' : 'admin.user_enabled', 'user', $user['id']);
        }

        return JsonResponse::success($response, $this->directory->find($user['id']));
    }

    /**
     * Sends a fresh invite to someone who has not chosen a password yet.
     *
     * @param array<string, string> $args
     */
    public function invite(Request $request, Response $response, array $args): Response
    {
        $owner = AdminScope::owner($request);
        $user = $this->directory->find((int) ($args['id'] ?? 0)) ?? throw ApiException::notFound();
        if ($user['status'] === 'active') {
            throw new ApiException(409, 'already_active', 'This person has already chosen a password. They can use “Forgot your password?”.');
        }
        if ($user['status'] === 'disabled') {
            throw new ApiException(409, 'disabled', 'Enable this account before inviting them again.');
        }

        $this->resets->invite($user['id']);
        $this->directory->markInvited($user['id']);
        $this->audit->record(Actor::user($owner->id), 'admin.user_invited', 'user', $user['id'], ['email' => $user['email'], 'again' => true]);

        return JsonResponse::success($response, $this->directory->find($user['id']));
    }

    /**
     * The role and provider sent, falling back to the current ones on an update. Provider accounts
     * belong to exactly one provider; owners and admins to none.
     *
     * @return array{Role, ?int}
     */
    private function roleAndProvider(Input $input, ?Role $currentRole = null, ?int $currentProvider = null): array
    {
        $role = $currentRole;
        if ($currentRole === null || $input->has('role')) {
            $role = Role::tryFrom((string) $input->oneOf('role', array_map(static fn(Role $r): string => $r->value, Role::cases())));
        }
        if ($role !== Role::Provider) {
            return [$role ?? Role::Admin, null];
        }
        $providerId = $input->has('provider_id') || $currentProvider === null ? $input->int('provider_id', min: 1) : $currentProvider;
        if ($providerId !== null && $this->providers->find($providerId) === null) {
            $input->reject('provider_id', 'Choose an existing provider.');
        }

        return [$role, $providerId];
    }
}
