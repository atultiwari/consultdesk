import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, path, renderAt, site } from '../support';
import { ADMIN, csrfOf, demoProvider, providerUser, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const users = [
  {
    id: 1,
    email: 'owner@example.test',
    name: 'Site Owner',
    role: 'owner',
    provider: null,
    status: 'active',
    last_login_at: '2026-10-05T00:00:00Z',
    telegram_linked: false,
  },
  {
    id: 3,
    email: 'new@example.test',
    name: null,
    role: 'admin',
    provider: null,
    status: 'invite_expired',
    last_login_at: null,
    telegram_linked: false,
  },
];

describe('owner menus', () => {
  it('shows organisation settings to owners only', async () => {
    mockApi({
      ...signedIn(providerUser),
      'GET /api/admin/dashboard': ok({ to_verify: [], to_approve: [], today: [], upcoming: [] }),
    });
    renderAt(ADMIN);

    const nav = await screen.findByRole('navigation', { name: 'Admin' });
    expect(within(nav).getByRole('link', { name: 'My account' })).toBeInTheDocument();
    for (const owners of ['Users', 'Branding', 'Payments', 'System']) {
      expect(within(nav).queryByRole('link', { name: owners })).not.toBeInTheDocument();
    }
  });
});

describe('users', () => {
  it('invites someone, then resends an expired invite and disables an account', async () => {
    let list: unknown[] = users;
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/users': () => ok(list),
      'POST /api/admin/users': (init) => {
        const body = JSON.parse(String(init?.body)) as { email: string };
        const created = {
          id: 4,
          email: body.email,
          name: 'New Teacher',
          role: 'provider',
          provider: { id: 7, name: 'Dr. Demo' },
          status: 'invited',
          last_login_at: null,
          telegram_linked: false,
        };
        list = [...list, created];
        return ok(created, 201);
      },
      'POST /api/admin/users/3/invite': ok({ ...users[1], status: 'invited' }),
      'PATCH /api/admin/users/3': (init) => {
        expect(csrfOf(init)).toBe('csrf-token-1');
        return ok({ ...users[1], status: 'disabled' });
      },
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/users`);

    const table = await screen.findByRole('table', { name: 'Users' });
    expect(within(table).getByText('Invite expired')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Invite someone' }));
    const dialog = screen.getByRole('dialog', { name: 'Invite someone' });
    await user.type(within(dialog).getByLabelText('Email'), 'teacher@example.test');
    await user.type(within(dialog).getByLabelText('Name'), 'New Teacher');
    await user.selectOptions(within(dialog).getByLabelText('Role'), 'provider');
    await user.selectOptions(within(dialog).getByLabelText('Provider'), '7');
    await user.click(within(dialog).getByRole('button', { name: 'Send invite' }));

    expect(calls.find((c) => c.path === '/api/admin/users' && c.method === 'POST')?.body).toEqual({
      email: 'teacher@example.test',
      name: 'New Teacher',
      role: 'provider',
      provider_id: 7,
    });
    expect(await within(table).findByText('teacher@example.test')).toBeInTheDocument();

    await user.click(
      within(table).getByRole('button', { name: 'Resend invite to new@example.test' }),
    );
    expect(await screen.findByText('Invite sent to new@example.test.')).toBeInTheDocument();

    await user.click(within(table).getByRole('button', { name: 'Disable new@example.test' }));
    await user.click(screen.getByRole('button', { name: 'Yes, disable' }));
    expect(calls.find((c) => c.method === 'PATCH')?.body).toEqual({ disabled: true });
  });

  it('shows why an invite could not be sent', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([]),
      'GET /api/admin/users': ok(users),
      'POST /api/admin/users': fail(422, 'validation_failed', 'Check the highlighted fields.', {
        email: 'Someone already has an account with this email.',
      }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/users`);

    await user.click(await screen.findByRole('button', { name: 'Invite someone' }));
    const dialog = screen.getByRole('dialog', { name: 'Invite someone' });
    await user.type(within(dialog).getByLabelText('Email'), 'owner@example.test');
    await user.click(within(dialog).getByRole('button', { name: 'Send invite' }));
    expect(
      await within(dialog).findByText('Someone already has an account with this email.'),
    ).toBeInTheDocument();
  });
});

describe('branding', () => {
  it('saves the name, preset and colours, and uploads a logo', async () => {
    let saved = { ...site, accent: null, accent_2: null, logo_url: null as string | null };
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/branding': () => ok(saved),
      'PUT /api/admin/branding': (init) => {
        saved = { ...saved, ...JSON.parse(String(init?.body)) };
        return ok(saved);
      },
      'POST /api/admin/branding/logo': (init) => {
        expect(init?.body).toBeInstanceOf(FormData);
        expect(csrfOf(init)).toBe('csrf-token-1');
        saved = { ...saved, logo_url: '/api/media/0123456789abcdef0123456789abcdef.webp' };
        return ok(saved);
      },
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/branding`);

    const name = await screen.findByLabelText('Organisation name');
    await user.clear(name);
    await user.type(name, 'VRL Academy');
    await user.click(screen.getByRole('radio', { name: /Vedant Research Labs/ }));
    await user.click(screen.getByLabelText('Use my own brand colour'));
    await user.clear(screen.getByLabelText('Brand colour (hex)'));
    await user.type(screen.getByLabelText('Brand colour (hex)'), '#3b2e7e');
    await user.click(screen.getByRole('button', { name: 'Save branding' }));

    expect(await screen.findByText('Saved.')).toBeInTheDocument();
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({
      org_name: 'VRL Academy',
      preset: 'vrl',
      accent: '#3b2e7e',
      accent_2: null,
    });

    const file = new File([new Uint8Array([137, 80, 78, 71])], 'logo.png', { type: 'image/png' });
    await user.upload(screen.getByLabelText('Upload a logo'), file);
    expect(await screen.findByRole('img', { name: 'Current logo' })).toHaveAttribute(
      'src',
      '/api/media/0123456789abcdef0123456789abcdef.webp',
    );
  });
});

describe('system', () => {
  it('shows health, applies database updates and retries failed jobs', async () => {
    let pending = ['007_admin_settings'];
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/system': () =>
        ok({
          version: '0.6.0',
          php: '8.1.34',
          migrations_pending: pending,
          cron: { last_run_at: null, healthy: false },
          outbox: { pending: 0, failed: 2, last_error: 'SMTP said no' },
        }),
      'POST /api/admin/system/migrate': () => {
        pending = [];
        return ok({ applied: ['007_admin_settings'] });
      },
      'POST /api/admin/system/retry-failed': ok({ retried: 2 }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/system`);

    expect(await screen.findByText(/Cron isn.t running/)).toBeInTheDocument();
    expect(screen.getByText('SMTP said no')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Run database updates' }));
    expect(await screen.findByText('Database is up to date.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Retry 2 failed jobs' }));
    expect(
      await screen.findByText('2 jobs will be retried on the next cron run.'),
    ).toBeInTheDocument();
    expect(calls.filter((c) => c.method === 'POST')).toHaveLength(2);
  });
});

describe('payments', () => {
  it('lists each provider’s UPI details with a link to edit them', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([
        demoProvider,
        { ...demoProvider, id: 8, name: 'Prof. Other', upi_vpa: null },
      ]),
    });
    renderAt(`${ADMIN}/payments`);

    const table = await screen.findByRole('table', { name: 'UPI details' });
    expect(within(table).getByText('placeholder@upi')).toBeInTheDocument();
    expect(within(table).getByText('Not set — UPI is off')).toBeInTheDocument();
    expect(within(table).getAllByRole('link', { name: /Edit/ })[1]).toHaveAttribute(
      'href',
      `${ADMIN}/providers/8`,
    );
    expect(path()).toBe(`${ADMIN}/payments`);
  });
});
