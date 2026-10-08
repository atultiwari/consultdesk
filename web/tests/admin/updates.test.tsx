import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, renderAt } from '../support';
import { ADMIN, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const healthy = {
  version: '0.9.0',
  php: '8.2.0',
  migrations_pending: [],
  cron: { last_run_at: '2026-10-08T00:00:00Z', healthy: true },
  outbox: { pending: 0, failed: 0, last_error: null },
  warnings: [],
};
const upToDate = {
  current: '0.9.0',
  latest: null,
  available: false,
  checked_at: null,
  error: null,
  last_update: null,
  can_update: false,
  blocker: null,
};
const available = {
  ...upToDate,
  latest: {
    version: '0.9.1',
    notes: '- Fixes the coupon box',
    published_at: '2026-10-09T00:00:00Z',
    page_url: 'https://github.com/atultiwari/consultdesk/releases/tag/v0.9.1',
    prerelease: false,
  },
  available: true,
  checked_at: '2026-10-09T01:00:00Z',
  can_update: true,
};

describe('updates', () => {
  it('checks for a new version and installs it after the password', async () => {
    let state: Record<string, unknown> = upToDate;
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/system': ok(healthy),
      'GET /api/admin/system/updates': () => ok(state),
      'POST /api/admin/system/updates/check': () => {
        state = available;
        return ok(state);
      },
      'POST /api/admin/system/updates/apply': (init) =>
        JSON.parse(String(init?.body)).password === 'right password here'
          ? ok({
              from: '0.9.0',
              to: '0.9.1',
              backup: 'consultdesk-before-update-x.sql.gz',
              migrations: [],
            })
          : fail(422, 'validation_failed', 'Check.', { password: 'That isn’t your password.' }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/system`);

    const panel = await screen.findByRole('region', { name: 'Updates' });
    expect(await within(panel).findByText(/ConsultDesk 0\.9\.0 is installed/)).toBeInTheDocument();
    await user.click(within(panel).getByRole('button', { name: 'Check for updates' }));
    expect(await within(panel).findByText('Version 0.9.1 is available')).toBeInTheDocument();
    expect(within(panel).getByText(/Fixes the coupon box/)).toBeInTheDocument();

    await user.type(within(panel).getByLabelText('Your password'), 'right password here');
    await user.click(within(panel).getByRole('button', { name: 'Update to 0.9.1' }));
    expect(await within(panel).findByText(/Updated to 0\.9\.1/)).toBeInTheDocument();
    expect(calls.find((c) => c.path === '/api/admin/system/updates/apply')?.body).toEqual({
      password: 'right password here',
    });
  });

  it('explains when updates can’t be installed from here', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/system': ok(healthy),
      'GET /api/admin/system/updates': ok({
        ...available,
        can_update: false,
        blocker: 'The zip PHP extension is needed.',
      }),
    });
    renderAt(`${ADMIN}/system`);

    const panel = await screen.findByRole('region', { name: 'Updates' });
    expect(await within(panel).findByText('The zip PHP extension is needed.')).toBeInTheDocument();
    expect(within(panel).queryByRole('button', { name: /Update to/ })).not.toBeInTheDocument();
  });

  it('tells the owner on the dashboard when a new version is out', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/dashboard': ok({ to_verify: [], to_approve: [], today: [], upcoming: [] }),
      'GET /api/admin/setup': ok({
        mode: 'single',
        completed: true,
        provider: null,
        template_sets: [],
      }),
      'GET /api/admin/system/updates': ok(available),
    });
    renderAt(ADMIN);

    expect(await screen.findByText(/ConsultDesk 0\.9\.1 is available/)).toBeInTheDocument();
  });
});
