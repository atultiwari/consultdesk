<?php

declare(strict_types=1);

namespace ConsultDesk\Http\Action;

use ConsultDesk\Admin\AdminUser;
use ConsultDesk\Admin\AdminUsers;
use ConsultDesk\Admin\Passwords;
use ConsultDesk\Admin\SystemStatus;
use ConsultDesk\Domain\Booking\Actor;
use ConsultDesk\Http\ApiException;
use ConsultDesk\Http\JsonInput;
use ConsultDesk\Http\JsonResponse;
use ConsultDesk\Http\Validation\Input;
use ConsultDesk\Infra\AuditLog;
use ConsultDesk\Infra\Backup;
use ConsultDesk\Infra\InvalidBackup;
use ConsultDesk\Infra\RateLimiter;
use ConsultDesk\Updates\UpdateChecker;
use ConsultDesk\Updates\UpdateFailed;
use ConsultDesk\Updates\Updater;
use ConsultDesk\Version;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Slim\Psr7\Stream;

/**
 * The owner's System panel: health, "Run database updates", "Retry failed jobs", and backups:
 * download one, or restore one made by this site. Both ask for the owner's password again.
 */
final class AdminSystemActions
{
    public function __construct(
        private readonly SystemStatus $system,
        private readonly AuditLog $audit,
        private readonly Backup $backups,
        private readonly AdminUsers $users,
        private readonly Passwords $passwords,
        private readonly RateLimiter $limiter,
        private readonly ?UpdateChecker $updates = null,
        private readonly ?Updater $updater = null,
    ) {}

    /** Wrong passwords allowed per hour when confirming a backup or restore, per account. */
    private const REAUTH_FAILURES = 5;

    /** Larger than any realistic booking site's compressed backup. */
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;
    private const RESTORE_WORD = 'RESTORE';

    public function show(Request $request, Response $response): Response
    {
        AdminScope::owner($request);

        return JsonResponse::success($response, $this->system->snapshot());
    }

    public function migrate(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        try {
            $applied = $this->system->migrate();
        } catch (RuntimeException $e) {
            error_log('ConsultDesk database update failed: ' . $e->getMessage());

            throw new ApiException(500, 'migration_failed', 'The database update did not finish. Restore your backup or see the server error log, then try again.');
        }
        if ($applied !== []) {
            $this->audit->record(Actor::user($owner->id), 'admin.migrated', 'system', null, ['applied' => $applied]);
        }

        return JsonResponse::success($response, ['applied' => $applied]);
    }

    public function retryFailed(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $retried = $this->system->retryFailed();
        $this->audit->record(Actor::user($owner->id), 'admin.outbox_retried', 'system', null, ['jobs' => $retried]);

        return JsonResponse::success($response, ['retried' => $retried]);
    }

    public function backup(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $input = new Input(JsonInput::decode($request));
        $this->confirmPassword($owner, $input, $input->secret('password', max: Passwords::MAX_LENGTH));
        $input->assertValid();

        self::longRunning();
        // Built in the backups folder and streamed from there; unlinked at once, so downloads aren't
        // kept on the server (the open handle still reads it).
        $path = $this->backups->create('backup');
        $handle = fopen($path, 'rb');
        $size = filesize($path);
        unlink($path);
        if ($handle === false) {
            throw new RuntimeException('Could not read the backup that was just made.');
        }
        $this->audit->record(Actor::user($owner->id), 'admin.backup_downloaded', 'system', null, ['bytes' => $size]);

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', 'application/gzip')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', basename($path)))
            ->withHeader('Cache-Control', 'no-store');
    }

    public function restore(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $form = $request->getParsedBody();
        $input = new Input(is_array($form) ? $form : []);
        $this->confirmPassword($owner, $input, $input->secret('password', max: Passwords::MAX_LENGTH));
        $confirm = $input->string('confirm', max: 20);
        if ($confirm !== null && $confirm !== self::RESTORE_WORD) {
            $input->reject('confirm', sprintf('Type %s to confirm.', self::RESTORE_WORD));
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if ($file instanceof UploadedFileInterface && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $input->reject('file', 'This server doesn’t accept files that large (upload_max_filesize). Restore it from the server’s shell instead: php bin/restore.php FILE');
        } elseif (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $input->reject('file', 'Choose a backup file (.sql.gz) made by this site.');
        } elseif (($file->getSize() ?? 0) > self::MAX_UPLOAD_BYTES) {
            $input->reject('file', 'That file is too large to be a backup of this site.');
        }
        $input->assertValid();

        self::longRunning();
        $path = sys_get_temp_dir() . '/cdrestore-' . bin2hex(random_bytes(8)) . '.sql.gz';
        try {
            /** @var UploadedFileInterface $file */
            self::copyToFile($file, $path);
            $safety = $this->backups->restore($path);
        } catch (InvalidBackup $e) {
            $this->audit->record(Actor::user($owner->id), 'admin.backup_restore_refused', 'system', null, ['reason' => $e->getMessage()]);

            throw new ApiException(422, 'invalid_backup', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('ConsultDesk restore failed: ' . $e->getMessage());

            throw new ApiException(500, 'restore_failed', 'The restore didn’t finish, so the data from before it was put back. See the server error log, or restore from the shell with php bin/restore.php.');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->audit->record(Actor::user($owner->id), 'admin.backup_restored', 'system', null, ['safety_backup' => basename($safety)]);

        return JsonResponse::success($response, ['restored' => true, 'safety_backup' => basename($safety)]);
    }

    /**
     * Checks the owner's password again. Wrong guesses are limited per account (not per address,
     * so a stolen session can't be used to guess the password from many places) and audited.
     */
    public function updates(Request $request, Response $response): Response
    {
        AdminScope::owner($request);

        return JsonResponse::success($response, $this->updateState($this->checker()->status()));
    }

    public function checkUpdates(Request $request, Response $response): Response
    {
        AdminScope::owner($request);

        return JsonResponse::success($response, $this->updateState($this->checker()->check()));
    }

    /**
     * Installs the newest release found by the last check, after the owner's password.
     */
    public function applyUpdate(Request $request, Response $response): Response
    {
        $owner = AdminScope::owner($request);
        $input = new Input(JsonInput::decode($request));
        $this->confirmPassword($owner, $input, $input->secret('password', max: Passwords::MAX_LENGTH));
        $input->assertValid();

        $release = $this->checker()->release();
        if ($release === null || !UpdateChecker::newer($release->version, Version::CURRENT)) {
            throw new ApiException(409, 'no_update', 'There’s no newer version to install. Check for updates first.');
        }
        self::longRunning();
        $updater = $this->updater ?? throw new RuntimeException('Updater not configured.');
        try {
            $result = $updater->apply($release);
        } catch (UpdateFailed $e) {
            $this->audit->record(Actor::user($owner->id), 'admin.update_failed', 'system', null, ['version' => $release->version, 'reason' => $e->getMessage()]);

            throw new ApiException(422, 'update_failed', $e->getMessage());
        }
        $this->audit->record(Actor::user($owner->id), 'admin.updated', 'system', null, $result);

        return JsonResponse::success($response, $result);
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>
     */
    private function updateState(array $status): array
    {
        $blocker = $this->updater === null ? 'Updates aren’t set up here.' : $this->updater->blocker();

        return [...$status, 'can_update' => $blocker === null && $status['available'] === true, 'blocker' => $blocker];
    }

    private function checker(): UpdateChecker
    {
        return $this->updates ?? throw new RuntimeException('Update checker not configured.');
    }

    private function confirmPassword(AdminUser $owner, Input $input, #[\SensitiveParameter] ?string $password): void
    {
        if ($password === null) {
            return;
        }
        $subject = 'user:' . $owner->id;
        if ($this->limiter->exceeded('reauth-failed', $subject, self::REAUTH_FAILURES, 3600)) {
            throw new ApiException(429, 'rate_limited', 'Too many wrong passwords. Try again in an hour.');
        }
        $hash = $this->users->findForLogin($owner->email)[1] ?? null;
        if (!$this->passwords->verify($password, $hash)) {
            $this->limiter->hit('reauth-failed', $subject, self::REAUTH_FAILURES, 3600);
            $this->audit->record(Actor::user($owner->id), 'admin.reauth_failed', 'user', $owner->id);
            $input->reject('password', 'That isn’t your password.');
        }
    }

    /** Copies the upload in chunks, so a large backup never sits in memory. */
    private static function copyToFile(UploadedFileInterface $file, string $path): void
    {
        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $out = fopen($path, 'xb') ?: throw new RuntimeException('Could not save the uploaded backup.');
        try {
            while (!$stream->eof()) {
                fwrite($out, $stream->read(1024 * 1024));
            }
        } finally {
            fclose($out);
        }
    }

    /** Backups and restores of a large site can take longer than PHP's usual 30 seconds. */
    private static function longRunning(): void
    {
        set_time_limit(0);
        ignore_user_abort(true);
    }
}
