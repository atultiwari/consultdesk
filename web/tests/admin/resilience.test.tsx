import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, renderAt } from '../support';
import { ADMIN, demoProvider, detail, session, signedIn, thesisService } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

describe('admin resilience', () => {
  it('goes back to sign-in when the session has expired', async () => {
    let expired = false;
    mockApi({
      ...signedIn(),
      'GET /api/admin/me': () =>
        expired ? fail(401, 'unauthenticated', 'Please sign in again.') : session(),
      'GET /api/admin/dashboard': () => {
        if (expired) return fail(401, 'unauthenticated', 'Please sign in again.');
        expired = true;
        return fail(401, 'unauthenticated', 'Please sign in again.');
      },
    });
    renderAt(ADMIN);

    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeInTheDocument();
  });

  it('refreshes a booking when an action turns out to be stale', async () => {
    let served = 0;
    mockApi({
      ...signedIn(),
      'GET /api/admin/bookings/11': () =>
        ok(
          served++ === 0
            ? detail
            : { ...detail, status: 'confirmed', actions: ['complete', 'no-show', 'cancel'] },
        ),
      'POST /api/admin/bookings/11/confirm': fail(
        409,
        'invalid_transition',
        'This booking was already settled.',
      ),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/bookings/11`);

    await user.click(await screen.findByRole('button', { name: 'Confirm' }));

    expect(await screen.findByText('This booking was already settled.')).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Mark completed' })).toBeInTheDocument();
  });

  it('keeps the pager on a page past the end', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([]),
      'GET /api/admin/bookings': {
        status: 200,
        body: { success: true, data: [], error: null, meta: { total: 30, page: 9, per_page: 25 } },
      },
    });
    renderAt(`${ADMIN}/bookings?page=9`);

    expect(await screen.findByText('No bookings on this page.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Previous page' })).toBeEnabled();
  });

  it('asks for a time instead of crashing when one is cleared', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/blocked': ok([]),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/blocked`);

    await user.type(await screen.findByLabelText('From'), '2026-10-21');
    await user.click(screen.getByLabelText('Whole days'));
    await user.clear(screen.getByLabelText('Until'));
    await user.click(screen.getByRole('button', { name: 'Block' }));

    expect(screen.getByText('Choose a start and end time.')).toBeInTheDocument();
  });

  it('does not turn a session free when the price is left empty', async () => {
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/providers/7/services': ok([thesisService]),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7?tab=sessions`);

    await user.click(await screen.findByRole('button', { name: 'Edit Thesis guidance' }));
    const editor = screen.getByRole('dialog', { name: 'Edit session' });
    await user.clear(within(editor).getByLabelText('Price (₹)'));
    await user.click(within(editor).getByRole('button', { name: 'Save session' }));

    expect(within(editor).getByText('Enter a price, or 0 for a free session.')).toBeInTheDocument();
    expect(calls.some((c) => c.method === 'PATCH')).toBe(false);
  });
});
