import { Button } from '../design/components/Button';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { visitorTimezone } from '../lib/time';
import { BackupPanel } from './BackupPanel';
import { UpdatesPanel } from './UpdatesPanel';
import { formatWhen } from './format';
import { useSystem, useSystemAction } from './settingsHooks';

export function SystemPage() {
  const system = useSystem();
  const migrate = useSystemAction<{ applied: string[] }>('/system/migrate');
  const retry = useSystemAction<{ retried: number }>('/system/retry-failed');
  const tz = visitorTimezone();
  const s = system.data;

  return (
    <div className="page">
      <header className="page__head">
        <h1>System</h1>
        <p className="page__sub">Health of this installation.</p>
      </header>
      {system.isPending && <Loading />}
      {system.isError && (
        <Notice tone="danger" live>
          {system.error.message}
        </Notice>
      )}
      {s?.warnings?.includes('owner_password_in_env') && (
        <Notice tone="warn" title="OWNER_PASSWORD is still set">
          <p>
            The owner’s password is in the server’s .env (or config.php). It’s handy for resetting a
            test site, but on a real site remove it now that the owner exists.
          </p>
        </Notice>
      )}
      {s && (
        <div className="detail-grid">
          <section className="panel" aria-labelledby="cron-h">
            <h2 id="cron-h">Scheduled tasks</h2>
            {s.cron.healthy && s.cron.last_run_at ? (
              <p>
                <Badge tone="success">Running</Badge> Last run {formatWhen(s.cron.last_run_at, tz)}.
              </p>
            ) : (
              <Notice tone="warn" title="Cron isn't running">
                <p>
                  Holds won't expire and emails, calendar events and Telegram alerts won't be sent
                  until it runs every minute.
                  {s.cron.last_run_at && ` Last run ${formatWhen(s.cron.last_run_at, tz)}.`} See the
                  Cron section of INSTALL.md.
                </p>
              </Notice>
            )}
          </section>
          <section className="panel" aria-labelledby="outbox-h">
            <h2 id="outbox-h">Emails, calendar and alerts</h2>
            <p>
              {s.outbox.pending} waiting · {s.outbox.failed} failed
            </p>
            {s.outbox.last_error && (
              <p className="cell-sub">
                Last error: <span className="mono">{s.outbox.last_error}</span>
              </p>
            )}
            {retry.isSuccess ? (
              <Notice tone="success" live>
                {retry.data.retried} {retry.data.retried === 1 ? 'job' : 'jobs'} will be retried on
                the next cron run.
              </Notice>
            ) : (
              s.outbox.failed > 0 && (
                <Button
                  variant="secondary"
                  onClick={() => retry.mutate()}
                  disabled={retry.isPending}
                >
                  Retry {s.outbox.failed} failed {s.outbox.failed === 1 ? 'job' : 'jobs'}
                </Button>
              )
            )}
          </section>
          <section className="panel" aria-labelledby="db-h">
            <h2 id="db-h">Database</h2>
            {s.migrations_pending.length === 0 ? (
              <p>Database is up to date.</p>
            ) : (
              <>
                <p>
                  {s.migrations_pending.length}{' '}
                  {s.migrations_pending.length === 1 ? 'update is' : 'updates are'} waiting:{' '}
                  <span className="mono">{s.migrations_pending.join(', ')}</span>. Take a backup
                  first.
                </p>
                <Button onClick={() => migrate.mutate()} disabled={migrate.isPending}>
                  Run database updates
                </Button>
              </>
            )}
            {migrate.isError && (
              <Notice tone="danger" live>
                {migrate.error.message}
              </Notice>
            )}
          </section>
          <section className="panel" aria-labelledby="version-h">
            <h2 id="version-h">Version</h2>
            <p>
              ConsultDesk <span className="mono">{s.version}</span> on PHP{' '}
              <span className="mono">{s.php}</span>
            </p>
          </section>
        </div>
      )}
      {s && <UpdatesPanel />}
      {s && <BackupPanel />}
    </div>
  );
}
