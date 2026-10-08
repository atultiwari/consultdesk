import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Notice } from '../design/components/Notice';
import { visitorTimezone } from '../lib/time';
import { formatWhen } from './format';
import { fieldErrors } from './forms';
import {
  useApplyUpdate,
  useCheckForUpdates,
  useUpdateStatus,
  type UpdateStatus,
} from './updateHooks';

/**
 * In-app updates, like WordPress: see what's new and install it in one step (a backup is taken
 * first, and only releases signed with ConsultDesk's release key are accepted).
 */
export function UpdatesPanel() {
  const status = useUpdateStatus();
  const check = useCheckForUpdates();
  const s = status.data;

  return (
    <section className="panel stack updates" aria-labelledby="updates-h">
      <h2 id="updates-h">Updates</h2>
      {s && (
        <p>
          ConsultDesk {s.current} is installed.
          {s.checked_at && (
            <span className="cell-sub">
              {' '}
              Last checked {formatWhen(s.checked_at, visitorTimezone())}.
            </span>
          )}
        </p>
      )}
      {s?.error && (
        <Notice tone="warn" live>
          {s.error}
        </Notice>
      )}
      {check.isError && (
        <Notice tone="danger" live>
          {check.error.message}
        </Notice>
      )}
      {s && s.available && s.latest ? (
        <Available status={s} />
      ) : (
        s?.checked_at && !s.error && <p className="cell-sub">You have the latest version.</p>
      )}
      <div className="form-actions">
        <Button variant="secondary" onClick={() => check.mutate()} disabled={check.isPending}>
          {check.isPending ? 'Checking…' : 'Check for updates'}
        </Button>
      </div>
    </section>
  );
}

function Available({ status }: { status: UpdateStatus }) {
  const apply = useApplyUpdate();
  const [password, setPassword] = useState('');
  const errors = fieldErrors(apply.error);
  const latest = status.latest;
  if (!latest) return null;

  if (apply.isSuccess) {
    return (
      <Notice tone="success" title={`Updated to ${apply.data.to}`} live>
        <p>
          A backup was saved first ({apply.data.backup})
          {apply.data.migrations.length > 0 && ', and the database was updated'}. Reload to use the
          new version.
        </p>
        <Button onClick={() => window.location.reload()}>Reload</Button>
      </Notice>
    );
  }

  const submit = (event: FormEvent) => {
    event.preventDefault();
    apply.mutate(password);
  };

  return (
    <div className="stack">
      <h3 className="updates__title">Version {latest.version} is available</h3>
      {latest.notes.trim() !== '' && <pre className="updates__notes">{latest.notes.trim()}</pre>}
      {latest.page_url.startsWith('https://github.com/') && (
        <p>
          <a href={latest.page_url} target="_blank" rel="noreferrer">
            Full release notes on GitHub
          </a>
        </p>
      )}
      {status.can_update ? (
        <form className="stack" onSubmit={submit} noValidate>
          <p className="hint">
            A backup is taken first. The booking site keeps working; anyone in the middle of booking
            may need to reload.
          </p>
          <Field label="Your password" error={errors.password}>
            <input
              className="input"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </Field>
          {apply.isError && !errors.password && (
            <Notice tone="danger" live>
              {apply.error.message}
            </Notice>
          )}
          <div className="form-actions">
            <Button type="submit" disabled={apply.isPending || password === ''}>
              {apply.isPending ? 'Updating… (about a minute)' : `Update to ${latest.version}`}
            </Button>
          </div>
        </form>
      ) : (
        status.blocker && <Notice tone="warn">{status.blocker}</Notice>
      )}
    </div>
  );
}
