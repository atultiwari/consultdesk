import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, renderAt } from '../support';
import { ADMIN, signedIn } from './fixtures';

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const healthy = {
  version: '0.8.0',
  php: '8.1.34',
  migrations_pending: [],
  cron: { last_run_at: '2026-10-05T00:00:00Z', healthy: true },
  outbox: { pending: 0, failed: 0, last_error: null },
  warnings: [] as string[],
};

describe('backups', () => {
  it('downloads a backup after the owner confirms their password', async () => {
    URL.createObjectURL = vi.fn(() => 'blob:backup');
    URL.revokeObjectURL = vi.fn();
    const clicked = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/system': ok(healthy),
      'POST /api/admin/system/backup': (init) =>
        JSON.parse(String(init?.body)).password === 'right password here'
          ? new Response('gz', {
              status: 200,
              headers: {
                'Content-Type': 'application/gzip',
                'Content-Disposition':
                  'attachment; filename="consultdesk-backup-20261005-000000.sql.gz"',
              },
            })
          : fail(422, 'validation_failed', 'Check the highlighted fields.', {
              password: 'That isn’t your password.',
            }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/system`);

    const password = (await screen.findAllByLabelText('Your password'))[0];
    await user.type(password, 'wrong');
    await user.click(screen.getByRole('button', { name: 'Download backup' }));
    expect(await screen.findByText('That isn’t your password.')).toBeInTheDocument();

    await user.clear(password);
    await user.type(password, 'right password here');
    await user.click(screen.getByRole('button', { name: 'Download backup' }));
    expect(
      await screen.findByText(/consultdesk-backup-20261005-000000\.sql\.gz/),
    ).toBeInTheDocument();
    expect(clicked).toHaveBeenCalledOnce();
    expect(calls.filter((c) => c.path === '/api/admin/system/backup')).toHaveLength(2);
  });

  it('restores only after the typed confirmation, then asks to sign in again', async () => {
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/system': ok(healthy),
      'POST /api/admin/system/restore': ok({
        restored: true,
        safety_backup: 'consultdesk-before-restore-20261005-000000.sql.gz',
      }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/system`);

    await user.upload(
      await screen.findByLabelText('Backup file'),
      new File(['gz'], 'backup.sql.gz', { type: 'application/gzip' }),
    );
    await user.type(screen.getAllByLabelText('Your password')[1], 'right password here');
    const restore = screen.getByRole('button', { name: 'Restore this backup' });
    expect(restore).toBeDisabled();
    await user.type(screen.getByLabelText(/Type RESTORE/), 'RESTORE');
    await user.click(restore);

    expect(await screen.findByText(/Everyone has been signed out/)).toBeInTheDocument();
    const sent = calls.find((c) => c.path === '/api/admin/system/restore')?.body;
    expect(sent).toBeInstanceOf(FormData);
    expect((sent as FormData).get('confirm')).toBe('RESTORE');
  });

  it('asks to remove OWNER_PASSWORD from .env once it has been used', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/system': ok({ ...healthy, warnings: ['owner_password_in_env'] }),
    });
    renderAt(`${ADMIN}/system`);

    expect(await screen.findByText(/OWNER_PASSWORD is still set/)).toBeInTheDocument();
  });
});
