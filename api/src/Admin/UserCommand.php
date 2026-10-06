<?php

declare(strict_types=1);

namespace ConsultDesk\Admin;

use Closure;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Infra\AuditLog;
use PDO;

/**
 * bin/user.php: create admin accounts, reset a password, list accounts. For the first owner and
 * for when nobody can sign in; everything else is done in the admin panel.
 */
final class UserCommand
{
    private const USAGE = <<<'TXT'
        Usage:
          php bin/user.php create --email=EMAIL --role=owner|admin|provider [--provider=SLUG] [--name=NAME]
          php bin/user.php reset-password --email=EMAIL
          php bin/user.php list

        TXT;

    /**
     * @param Closure(string): string $askPassword reads a password without echoing it
     * @param Closure(string): void   $write
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminUsers $users,
        private readonly Passwords $passwords,
        private readonly Sessions $sessions,
        private readonly AuditLog $audit,
        private readonly Closure $askPassword,
        private readonly Closure $write,
    ) {}

    /**
     * @param list<string> $args everything after the script name
     *
     * @return int exit code
     */
    public function run(array $args): int
    {
        $options = self::options(array_slice($args, 1));

        return match ($args[0] ?? '') {
            'create' => $this->create($options),
            'reset-password' => $this->resetPassword($options),
            'list' => $this->list(),
            default => $this->fail(self::USAGE),
        };
    }

    /**
     * @param array<string, string> $options
     */
    private function create(array $options): int
    {
        $email = strtolower(trim($options['email'] ?? ''));
        $role = Role::tryFrom($options['role'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail("Give a valid --email.\n");
        }
        if ($role === null) {
            return $this->fail("--role must be owner, admin or provider.\n");
        }
        if ($this->users->findForLogin($email) !== null) {
            return $this->fail("A user with that email already exists. Use reset-password instead.\n");
        }
        $providerId = null;
        if ($role === Role::Provider) {
            $providerId = $this->providerId($options['provider'] ?? '');
            if ($providerId === null) {
                return $this->fail("Provider accounts need --provider=SLUG of an existing provider.\n");
            }
        }
        $name = trim($options['name'] ?? '');
        if (mb_strlen($name) > 120) {
            return $this->fail("--name is at most 120 characters.\n");
        }
        $password = $this->newPassword();
        if ($password === null) {
            return 1;
        }

        $id = $this->users->create($email, $this->passwords->hash($password), $role, $providerId, $name === '' ? null : $name);
        $this->audit->record(Actor::system(), 'admin.user_created', 'user', $id, ['email' => $email, 'role' => $role->value, 'via' => 'cli']);
        ($this->write)(sprintf("Created %s %s (id %d).\n", $role->value, $email, $id));

        return 0;
    }

    /**
     * @param array<string, string> $options
     */
    private function resetPassword(array $options): int
    {
        $found = $this->users->findForLogin($options['email'] ?? '');
        if ($found === null) {
            return $this->fail("No user with that email.\n");
        }
        [$user] = $found;
        $password = $this->newPassword();
        if ($password === null) {
            return 1;
        }

        $this->users->setPasswordHash($user->id, $this->passwords->hash($password));
        $this->sessions->endAll($user->id);
        $this->audit->record(Actor::system(), 'admin.password_reset', 'user', $user->id, ['via' => 'cli']);
        ($this->write)(sprintf("Password changed for %s. They have been signed out everywhere.\n", $user->email));

        return 0;
    }

    private function list(): int
    {
        $rows = $this->pdo->query(
            'SELECT u.id, u.email, u.role, p.slug, u.last_login_at FROM users u
             LEFT JOIN providers p ON p.id = u.provider_id ORDER BY u.id',
        );
        $lines = [sprintf("%-5s %-40s %-9s %-20s %s\n", 'ID', 'EMAIL', 'ROLE', 'PROVIDER', 'LAST SIGN-IN (UTC)')];
        foreach ($rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines[] = sprintf("%-5d %-40s %-9s %-20s %s\n", $r['id'], $r['email'], $r['role'], $r['slug'] ?? '-', $r['last_login_at'] ?? 'never');
        }
        ($this->write)(implode('', $lines));

        return 0;
    }

    private function newPassword(): ?string
    {
        $password = ($this->askPassword)(sprintf('New password (%d+ characters): ', Passwords::MIN_LENGTH));
        if (!Passwords::acceptable($password)) {
            $this->fail(sprintf("The password must be %d to %d characters.\n", Passwords::MIN_LENGTH, Passwords::MAX_LENGTH));

            return null;
        }
        if (!hash_equals($password, ($this->askPassword)('Repeat it: '))) {
            $this->fail("The passwords did not match.\n");

            return null;
        }

        return $password;
    }

    private function providerId(string $slug): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM providers WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function fail(string $message): int
    {
        ($this->write)($message);

        return 1;
    }

    /**
     * Parses --key=value pairs.
     *
     * @param list<string> $args
     *
     * @return array<string, string>
     */
    private static function options(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z-]+)=(.*)$/s', $arg, $m) === 1) {
                $options[$m[1]] = $m[2];
            }
        }

        return $options;
    }
}
