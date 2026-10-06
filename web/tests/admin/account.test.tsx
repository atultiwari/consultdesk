import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, path, renderAt } from '../support';
import { ADMIN, demoProvider, providerUser, signedIn, signedOut, thesisService } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

describe('my account', () => {
  it('renames me, changes my password and links my Telegram', async () => {
    let attempt = 0;
    const calls = mockApi({
      ...signedIn(providerUser),
      'PATCH /api/admin/me': () =>
        ok({ user: { ...providerUser, name: 'Dr. Demo' }, csrf_token: 'csrf-token-1' }),
      'POST /api/admin/me/password': () =>
        attempt++ === 0
          ? fail(422, 'validation_failed', 'Check the highlighted fields.', {
              current_password: 'That isn’t your current password.',
            })
          : ok({ ok: true }),
      'GET /api/admin/me/telegram': ok({ configured: true, linked: false }),
      'POST /api/admin/me/telegram/link': ok({ url: 'https://t.me/ConsultDeskTestBot?start=abc' }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/account`);

    await user.type(await screen.findByLabelText('Your name'), 'Dr. Demo');
    await user.click(screen.getByRole('button', { name: 'Save name' }));
    expect(await screen.findByText('Dr. Demo', { selector: '.account__name' })).toBeInTheDocument();

    await user.type(screen.getByLabelText('Current password'), 'wrong-one');
    await user.type(screen.getByLabelText('New password'), 'a long new password');
    await user.click(screen.getByRole('button', { name: 'Change password' }));
    expect(await screen.findByText('That isn’t your current password.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Change password' }));
    expect(await screen.findByText(/Password changed/)).toBeInTheDocument();
    expect(calls.filter((c) => c.path === '/api/admin/me/password').at(-1)?.body).toEqual({
      current_password: 'wrong-one',
      new_password: 'a long new password',
    });

    await user.click(screen.getByRole('button', { name: 'Get a Telegram link' }));
    expect(await screen.findByRole('link', { name: /Open in Telegram/ })).toHaveAttribute(
      'href',
      'https://t.me/ConsultDeskTestBot?start=abc',
    );
  });
});

describe('welcome page', () => {
  it('lets an invited person choose a password', async () => {
    const calls = mockApi({ ...signedOut, 'POST /api/admin/password/reset': ok({ ok: true }) });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/welcome?token=invite-token-abc`);

    expect(
      await screen.findByRole('heading', { name: 'Welcome! Choose a password' }),
    ).toBeInTheDocument();
    await waitFor(() => expect(path()).toBe(`${ADMIN}/welcome`));
    await user.type(screen.getByLabelText('New password'), 'my chosen password');
    await user.type(screen.getByLabelText('Repeat it'), 'my chosen password');
    await user.click(screen.getByRole('button', { name: 'Set password' }));

    expect(await screen.findByText(/You can sign in now/)).toBeInTheDocument();
    expect(calls.at(-1)?.body).toMatchObject({ token: 'invite-token-abc' });
  });
});

describe('provider connections and photo', () => {
  const routes = {
    'GET /api/admin/providers': ok([demoProvider]),
    'GET /api/admin/providers/7/services': ok([thesisService]),
  };

  it('links Telegram and connects Google Calendar from the panel', async () => {
    let connected = false;
    const calls = mockApi({
      ...signedIn(providerUser),
      ...routes,
      'GET /api/admin/providers/7/integrations': () =>
        ok({
          telegram: { configured: true, linked: false },
          google: connected
            ? {
                configured: true,
                connected: true,
                active: true,
                account_email: 'provider@example.test',
                busy_calendar_ids: ['provider@example.test'],
                target_calendar_id: 'provider@example.test',
              }
            : { configured: true, connected: false },
        }),
      'POST /api/admin/providers/7/telegram/link': ok({
        url: 'https://t.me/ConsultDeskTestBot?start=xyz',
      }),
      'POST /api/admin/providers/7/google/connect': ok({
        url: 'https://accounts.example.test/auth?state=s',
      }),
      'GET /api/admin/providers/7/google/calendars': ok([
        { id: 'provider@example.test', summary: 'Provider', primary: true, writable: true },
        { id: 'team@group.calendar.google.com', summary: 'Team', primary: false, writable: false },
      ]),
      'PUT /api/admin/providers/7/google/calendars': ok({}),
    });
    const user = userEvent.setup();
    const view = renderAt(`${ADMIN}/providers/7?tab=connections`);

    await user.click(await screen.findByRole('button', { name: 'Get a Telegram link' }));
    expect(await screen.findByRole('link', { name: /Open in Telegram/ })).toHaveAttribute(
      'href',
      'https://t.me/ConsultDeskTestBot?start=xyz',
    );

    await user.type(screen.getByLabelText('Google account email'), 'provider@example.test');
    await user.click(screen.getByRole('button', { name: 'Get a Google link' }));
    expect(await screen.findByRole('link', { name: /Open Google/ })).toHaveAttribute(
      'href',
      'https://accounts.example.test/auth?state=s',
    );

    connected = true;
    view.unmount();
    renderAt(`${ADMIN}/providers/7?tab=connections`);
    const google = await screen.findByRole('region', { name: 'Google Calendar' });
    expect(
      await within(google).findByText('provider@example.test', { selector: 'strong' }),
    ).toBeInTheDocument();
    await user.click(await within(google).findByLabelText('Team'));
    await user.click(within(google).getByRole('button', { name: 'Save calendars' }));
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({
      busy: ['provider@example.test', 'team@group.calendar.google.com'],
      target: 'provider@example.test',
    });
  });

  it('uploads a profile photo', async () => {
    mockApi({
      ...signedIn(),
      ...routes,
      'POST /api/admin/providers/7/photo': ok({
        ...demoProvider,
        photo_url: '/api/media/0123456789abcdef0123456789abcdef.webp',
      }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7`);

    await user.upload(
      await screen.findByLabelText('Upload a photo'),
      new File([new Uint8Array([1])], 'me.jpg', { type: 'image/jpeg' }),
    );
    expect(await screen.findByRole('img', { name: 'Dr. Demo' })).toHaveAttribute(
      'src',
      '/api/media/0123456789abcdef0123456789abcdef.webp',
    );
  });
});
