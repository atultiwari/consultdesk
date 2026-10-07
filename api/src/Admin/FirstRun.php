<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Infra\AuditLog;
use PDO;
use RuntimeException;

/**
 * Creating the first owner on a site with no accounts yet, like WordPress's first-run screen: from
 * the form at the admin path, or automatically from OWNER_EMAIL/OWNER_PASSWORD in .env. A database
 * lock makes sure only one owner can ever be created this way, even if two requests race.
 */
final class FirstRun
{
    private const LOCK = 'consultdesk_first_owner';
    private const LOCK_SECONDS = 5;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminUsers $users,
        private readonly Passwords $passwords,
        private readonly AuditLog $audit,
        private readonly ?OwnerDefaults $defaults = null,
        #[\SensitiveParameter]
        private readonly ?string $setupKey = null,
    ) {}

    public function needed(): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users');
        $statement->execute();

        return (int) $statement->fetchColumn() === 0;
    }

    public function needsSetupKey(): bool
    {
        return $this->setupKey !== null;
    }

    public function setupKeyMatches(#[\SensitiveParameter] ?string $given): bool
    {
        return $this->setupKey === null || ($given !== null && hash_equals($this->setupKey, $given));
    }

    /**
     * The owner details from .env to fill in the form (never the password).
     *
     * @return array{email: string, name: ?string}|null
     */
    public function suggested(): ?array
    {
        return $this->defaults === null ? null : ['email' => $this->defaults->email, 'name' => $this->defaults->name];
    }

    /**
     * With OWNER_EMAIL and OWNER_PASSWORD in .env, creates that owner on a site with no accounts.
     *
     * @return int|null the new owner's id
     */
    public function createFromEnv(): ?int
    {
        if ($this->defaults?->password === null || !$this->needed()) {
            return null;
        }

        try {
            return $this->create($this->defaults->email, $this->defaults->name, $this->defaults->password, 'admin.first_owner_from_env');
        } catch (RuntimeException) {
            return null; // another request holds the lock and is creating the owner right now
        }
    }

    /**
     * @return int|null the new owner's id, or null when the site already has an account
     */
    public function create(string $email, ?string $name, #[\SensitiveParameter] string $password, string $action = 'admin.first_owner_created'): ?int
    {
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:name, :seconds)');
        $lock->execute(['name' => self::LOCK, 'seconds' => self::LOCK_SECONDS]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Another request is creating the owner; try again.');
        }
        try {
            if (!$this->needed()) {
                return null;
            }
            $id = $this->users->create($email, $this->passwords->hash($password), Role::Owner, null, $name);
            $this->audit->record(Actor::user($id), $action, 'user', $id);

            return $id;
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => self::LOCK]);
        }
    }
}
