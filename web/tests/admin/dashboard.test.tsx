import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { mockApi, ok, renderAt } from '../support';
import { ADMIN, csrfOf, detail, providerUser, row, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

describe('admin dashboard', () => {
  it('lists payments to verify and confirms one in place', async () => {
    let confirmed = false;
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/dashboard': () =>
        ok({
          to_verify: confirmed ? [] : [row],
          to_approve: [],
          today: [],
          upcoming: confirmed ? [{ ...row, status: 'confirmed' }] : [],
        }),
      'POST /api/admin/bookings/11/confirm': (init) => {
        expect(csrfOf(init)).toBe('csrf-token-1');
        confirmed = true;
        return ok({ ...detail, status: 'confirmed', actions: ['complete', 'no-show', 'cancel'] });
      },
    });
    const user = userEvent.setup();
    renderAt(ADMIN);

    const queue = await screen.findByRole('region', { name: /Payments to verify/ });
    expect(within(queue).getByText('412345678901')).toBeInTheDocument();
    expect(within(queue).getByText('Asha Placeholder')).toBeInTheDocument();
    expect(within(queue).getByText('₹2,999')).toBeInTheDocument();

    await user.click(within(queue).getByRole('button', { name: 'Confirm CD-7F3K' }));

    expect(await screen.findByText('Nothing waiting. Nice.')).toBeInTheDocument();
    expect(calls.some((c) => c.path === '/api/admin/bookings/11/confirm')).toBe(true);
  });

  it('asks the viewer’s timezone for "today" and shows a provider their own menu', async () => {
    const calls = mockApi({
      ...signedIn(providerUser),
      'GET /api/admin/dashboard': ok({ to_verify: [], to_approve: [], today: [], upcoming: [] }),
    });
    renderAt(ADMIN);

    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    expect(calls.find((c) => c.path.startsWith('/api/admin/dashboard'))?.path).toMatch(/\?tz=/);
    const nav = screen.getByRole('navigation', { name: 'Admin' });
    expect(within(nav).getByRole('link', { name: 'My profile' })).toHaveAttribute(
      'href',
      `${ADMIN}/providers/7`,
    );
    expect(within(nav).queryByRole('link', { name: 'Providers' })).not.toBeInTheDocument();
  });
});
