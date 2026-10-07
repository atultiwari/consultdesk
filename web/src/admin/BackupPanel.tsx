import { useState, type FormEvent } from 'react';
import { fieldErrors } from './forms';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Notice } from '../design/components/Notice';
import { useDownloadBackup, useRestoreBackup, useSignInAgain } from './backupHooks';

const RESTORE_WORD = 'RESTORE';

/** Download a backup of everything, or put one back. Both ask for the owner's password again. */
export function BackupPanel() {
  return (
    <section className="panel stack backup" aria-labelledby="backup-h">
      <h2 id="backup-h">Backups</h2>
      <p className="hint">
        A backup holds every booking, teacher, session and setting. Saved keys stay encrypted inside
        it, so it can only be restored on this site (the same APP_KEY).
      </p>
      <DownloadBackup />
      <RestoreBackup />
    </section>
  );
}

function DownloadBackup() {
  const download = useDownloadBackup();
  const [password, setPassword] = useState('');
  const errors = fieldErrors(download.error);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    download.mutate(password, { onSuccess: () => setPassword('') });
  };

  return (
    <form className="stack" onSubmit={submit} noValidate>
      <h3 className="backup__title">Download a backup</h3>
      <Field label="Your password" error={errors.password}>
        <input
          className="input"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
      </Field>
      {download.isError && !errors.password && (
        <Notice tone="danger" live>
          {download.error.message}
        </Notice>
      )}
      {download.isSuccess && (
        <Notice tone="success" live>
          Saved <span className="mono">{download.data}</span>. Keep it somewhere private.
        </Notice>
      )}
      <div className="form-actions">
        <Button type="submit" variant="secondary" disabled={download.isPending || password === ''}>
          {download.isPending ? 'Preparing…' : 'Download backup'}
        </Button>
      </div>
    </form>
  );
}

function RestoreBackup() {
  const restore = useRestoreBackup();
  const signInAgain = useSignInAgain();
  const [file, setFile] = useState<File | null>(null);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const errors = fieldErrors(restore.error);
  const ready = file !== null && password !== '' && confirm === RESTORE_WORD;

  if (restore.isSuccess) {
    return (
      <Notice tone="success" title="Backup restored" live>
        <p>
          Everyone has been signed out. What was here before is saved on the server as{' '}
          <span className="mono">{restore.data.safety_backup}</span>.
        </p>
        <Button onClick={signInAgain}>Sign in again</Button>
      </Notice>
    );
  }

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (file && ready) restore.mutate({ file, password, confirm });
  };

  return (
    <form className="stack" onSubmit={submit} noValidate>
      <h3 className="backup__title">Restore a backup</h3>
      <Notice tone="warn">
        Restoring replaces everything on this site with the backup, and signs everyone out. A copy
        of what’s here now is saved first.
      </Notice>
      <Field label="Backup file" error={errors.file}>
        <input
          className="input"
          type="file"
          accept=".gz,application/gzip"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        />
      </Field>
      <Field label="Your password" error={errors.password}>
        <input
          className="input"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
        />
      </Field>
      <Field label={`Type ${RESTORE_WORD} to confirm`} error={errors.confirm}>
        <input
          className="input input--short"
          autoComplete="off"
          value={confirm}
          onChange={(e) => setConfirm(e.target.value)}
        />
      </Field>
      {restore.isError && Object.keys(errors).length === 0 && (
        <Notice tone="danger" live>
          {restore.error.message}
        </Notice>
      )}
      <div className="form-actions">
        <Button type="submit" className="btn--danger" disabled={!ready || restore.isPending}>
          {restore.isPending ? 'Restoring…' : 'Restore this backup'}
        </Button>
      </div>
    </form>
  );
}
