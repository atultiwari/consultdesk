import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, path, renderAt, site } from '../support';
import { ADMIN, csrfOf, session, signedOut } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const emptyDashboard = ok({ to_verify: [], to_approve: [], today: [], upcoming: [] });

describe('admin sign-in', () => {
  it('shows the ordinary 404 page when the path is not the admin path', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/admin/entry/not-the-admin-path': fail(404, 'not_found', 'Not found.'),
    });
    renderAt('/not-the-admin-path');

    expect(
      await screen.findByRole('heading', { name: /This page doesn.t exist/ }),
    ).toBeInTheDocument();
  });

  it('signs in with the secret path and then sends the CSRF token on writes', async () => {
    let signedIn = false;
    const calls = mockApi({
      ...signedOut,
      'GET /api/admin/me': () =>
        signedIn ? session() : fail(401, 'unauthenticated', 'Please sign in again.'),
      'POST /api/admin/login': () => {
        signedIn = true;
        return session();
      },
      'GET /api/admin/dashboard': emptyDashboard,
      'POST /api/admin/logout': (init) => {
        expect(csrfOf(init)).toBe('csrf-token-1');
        signedIn = false;
        return ok({ ok: true });
      },
    });
    const user = userEvent.setup();
    renderAt(ADMIN);

    await user.type(await screen.findByLabelText('Email'), 'owner@example.test');
    await user.type(screen.getByLabelText('Password'), 'correct horse battery staple');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    expect(calls.find((c) => c.path === '/api/admin/login')?.body).toEqual({
      path: 'desk-7q2x-placeholder',
      email: 'owner@example.test',
      password: 'correct horse battery staple',
    });

    await user.click(screen.getByRole('button', { name: /Account/ }));
    await user.click(screen.getByRole('menuitem', { name: 'Sign out' }));
    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeInTheDocument();
  });

  it('explains a failed sign-in', async () => {
    mockApi({
      ...signedOut,
      'POST /api/admin/login': fail(401, 'invalid_credentials', 'Wrong email or password.'),
    });
    const user = userEvent.setup();
    renderAt(ADMIN);

    await user.type(await screen.findByLabelText('Email'), 'owner@example.test');
    await user.type(screen.getByLabelText('Password'), 'not the password');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByText('Wrong email or password.')).toBeInTheDocument();
  });

  it('sends a reset link without saying whether the account exists', async () => {
    const calls = mockApi({ ...signedOut, 'POST /api/admin/password/forgot': ok({ ok: true }) });
    const user = userEvent.setup();
    renderAt(ADMIN);

    await user.click(await screen.findByRole('link', { name: 'Forgot your password?' }));
    await user.type(screen.getByLabelText('Email'), 'owner@example.test');
    await user.click(screen.getByRole('button', { name: 'Email me a link' }));

    expect(await screen.findByText(/If that address has an account/)).toBeInTheDocument();
    expect(calls.at(-1)?.body).toEqual({
      path: 'desk-7q2x-placeholder',
      email: 'owner@example.test',
    });
  });

  it('sets a new password from the emailed link and hides the token from the address bar', async () => {
    const calls = mockApi({ ...signedOut, 'POST /api/admin/password/reset': ok({ ok: true }) });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/reset?token=reset-token-abc`);

    await user.type(await screen.findByLabelText('New password'), 'a brand new password');
    expect(path()).toBe(`${ADMIN}/reset`);
    await user.type(screen.getByLabelText('Repeat it'), 'a brand new pasword');
    await user.click(screen.getByRole('button', { name: 'Set password' }));
    expect(await screen.findByText('The passwords do not match.')).toBeInTheDocument();

    await user.clear(screen.getByLabelText('Repeat it'));
    await user.type(screen.getByLabelText('Repeat it'), 'a brand new password');
    await user.click(screen.getByRole('button', { name: 'Set password' }));

    expect(await screen.findByText(/Your password has been changed/)).toBeInTheDocument();
    expect(calls.at(-1)?.body).toEqual({
      path: 'desk-7q2x-placeholder',
      token: 'reset-token-abc',
      password: 'a brand new password',
    });
  });
});
