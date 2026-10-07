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
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

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
    ) {}

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

        // Built in the backups folder and removed once read: downloads aren't kept on the server.
        $path = $this->backups->create('backup');
        try {
            $contents = file_get_contents($path);
        } finally {
            unlink($path);
        }
        if ($contents === false) {
            throw new RuntimeException('Could not read the backup that was just made.');
        }
        $this->audit->record(Actor::user($owner->id), 'admin.backup_downloaded', 'system', null, ['bytes' => strlen($contents)]);
        $response->getBody()->write($contents);

        return $response
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
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $input->reject('file', 'Choose a backup file (.sql.gz) made by this site.');
        } elseif (($file->getSize() ?? 0) > self::MAX_UPLOAD_BYTES) {
            $input->reject('file', 'That file is too large to be a backup of this site.');
        }
        $input->assertValid();

        $path = (string) tempnam(sys_get_temp_dir(), 'cdrestore');
        try {
            /** @var UploadedFileInterface $file */
            file_put_contents($path, (string) $file->getStream());
            $safety = $this->backups->restore($path);
        } catch (InvalidBackup $e) {
            throw new ApiException(422, 'invalid_backup', $e->getMessage());
        } finally {
            unlink($path);
        }
        $this->audit->record(Actor::user($owner->id), 'admin.backup_restored', 'system', null, ['safety_backup' => basename($safety)]);

        return JsonResponse::success($response, ['restored' => true, 'safety_backup' => basename($safety)]);
    }

    private function confirmPassword(AdminUser $owner, Input $input, #[\SensitiveParameter] ?string $password): void
    {
        if ($password === null) {
            return;
        }
        $hash = $this->users->findForLogin($owner->email)[1] ?? null;
        if (!$this->passwords->verify($password, $hash)) {
            $input->reject('password', 'That isn’t your password.');
        }
    }
}
