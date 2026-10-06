<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\AdminUsers;
use ConsultDesk\Admin\PasswordResets;
use ConsultDesk\Admin\ProviderSettings;
use ConsultDesk\Admin\Role;
use ConsultDesk\Admin\Sessions;
use ConsultDesk\Admin\TelegramLinks;
use ConsultDesk\Admin\UserDirectory;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The owner's Users panel: invite people, change their role, disable them. New users get an email
 * link to choose their own password; the owner never sees or sets it.
 */
final class AdminUserActions
{
    /** Matches PasswordResets: more invite emails than this an hour would be dropped. */
    private const MAX_INVITES_PER_HOUR = 5;

    public function __construct(
        private readonly UserDirectory $directory,
        private readonly AdminUsers $users,
        private readonly ProviderSettings $providers,
        private readonly PasswordResets $resets,
        private readonly Sessions $sessions,
        private readonly TelegramLinks $telegram,
        private readonly Db $db,
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
        $name = $input->has('name') ? $input->personName('name', required: false) : null;
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

        $name = $input->has('name') ? $input->personName('name', required: false) : $user['name'];
        [$role, $providerId] = $this->roleAndProvider($input, Role::from((string) $user['role']), $user['provider']['id'] ?? null);
        if ($self && $role !== Role::Owner) {
            $input->reject('role', 'You can’t change your own role. Ask another owner.');
        }
        $disabled = $input->has('disabled') ? $input->bool('disabled', required: true) : null;
        if ($self && $disabled === true) {
            $input->reject('disabled', 'You can’t disable your own account.');
        }
        $input->assertValid();

        $this->db->transaction(fn() => $this->apply($user, is_string($name) ? $name : null, $role, $providerId, $disabled, Actor::user($owner->id)));

        return JsonResponse::success($response, $this->directory->find($user['id']));
    }

    /**
     * Saves the changes and their consequences together. A changed role or a disabled account ends
     * the person's sessions, unlinks their Telegram (whose buttons act with their old powers) and,
     * when disabled, voids any invite or reset link they still hold.
     *
     * @param array<string, mixed> $user
     */
    private function apply(array $user, ?string $name, Role $role, ?int $providerId, ?bool $disabled, Actor $actor): void
    {
        $id = (int) $user['id'];
        $this->directory->update($id, $name, $role, $providerId);
        $roleChanged = $role->value !== $user['role'] || $providerId !== ($user['provider']['id'] ?? null);
        $disabling = $disabled === true && $user['status'] !== 'disabled';
        if ($roleChanged || $disabling) {
            $this->sessions->endAll($id);
            $this->telegram->unlinkUser($id);
        }
        if ($roleChanged) {
            $this->audit->record($actor, 'admin.user_role_changed', 'user', $id, ['from' => $user['role'], 'to' => $role->value, 'provider_id' => $providerId]);
        }
        if ($disabled !== null && $disabled !== ($user['status'] === 'disabled')) {
            $this->directory->setDisabled($id, $disabled);
            if ($disabling) {
                $this->resets->retireAll($id);
            }
            $this->audit->record($actor, $disabling ? 'admin.user_disabled' : 'admin.user_enabled', 'user', $id);
        }
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
        if ($this->directory->invitesLastHour($user['id']) >= self::MAX_INVITES_PER_HOUR) {
            throw new ApiException(429, 'too_many_invites', 'That’s a lot of invites in an hour. Check the address, and try again later.');
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
