<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Infra\AuditLog;

/**
 * Admin sign-in. Unknown emails and wrong passwords behave the same (same error, same hashing work),
 * and five failures lock both the account and the address for 15 minutes.
 */
final class AuthService
{
    public function __construct(
        private readonly AdminUsers $users,
        private readonly Passwords $passwords,
        private readonly Sessions $sessions,
        private readonly LoginThrottle $throttle,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws AuthFailed
     */
    public function login(string $email, #[\SensitiveParameter] string $password, string $ip, string $userAgent): AdminSession
    {
        if ($this->throttle->isLocked($ip, $email)) {
            throw AuthFailed::tooManyAttempts();
        }

        $found = $this->users->findForLogin($email);
        if (!$this->passwords->verify($password, $found[1] ?? null) || $found === null) {
            $this->throttle->record($ip, $email, false);
            throw AuthFailed::invalidCredentials();
        }

        [$user, $hash] = $found;
        $this->throttle->record($ip, $email, true);
        if ($this->passwords->needsRehash($hash)) {
            $this->users->setPasswordHash($user->id, $this->passwords->hash($password));
        }
        $this->users->recordLogin($user->id);
        $this->audit->record(Actor::user($user->id), 'admin.login', 'user', $user->id);

        // A brand-new session on every sign-in (no fixation).
        return $this->sessions->start($user, $ip, $userAgent);
    }

    public function logout(AdminSession $session): void
    {
        $this->sessions->end($session->token);
    }

    public function logoutEverywhere(AdminSession $session): void
    {
        $this->sessions->endAll($session->user->id);
        $this->audit->record(Actor::user($session->user->id), 'admin.logout_all', 'user', $session->user->id);
    }
}
