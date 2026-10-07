import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, renderAt } from '../support';
import { ADMIN, providerUser, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const noBookings = {
  success: true,
  data: [],
  error: null,
  meta: { total: 0, page: 1, per_page: 25 },
};

describe('booking codes', () => {
  it('lets staff change what booking codes start with', async () => {
    let prefix = 'CD';
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([]),
      'GET /api/admin/bookings': { body: noBookings },
      'GET /api/admin/booking-codes': () => ok({ prefix, default: 'CD' }),
      'PUT /api/admin/booking-codes': (init) => {
        const sent = JSON.parse(String(init?.body)).prefix as string;
        if (sent === 'V')
          return fail(422, 'validation_failed', 'Check.', {
            prefix: 'Use 2–6 letters or digits, starting with a letter, e.g. VRL.',
          });
        prefix = sent;
        return ok({ prefix, default: 'CD' });
      },
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/bookings`);

    await user.click(await screen.findByText(/Booking codes start with/));
    const field = screen.getByLabelText('Prefix');
    await user.clear(field);
    await user.type(field, 'V');
    await user.click(screen.getByRole('button', { name: 'Save prefix' }));
    expect(await screen.findByText(/Use 2–6 letters/)).toBeInTheDocument();

    await user.clear(field);
    await user.type(field, 'vrl');
    await user.click(screen.getByRole('button', { name: 'Save prefix' }));
    expect(await screen.findByText(/New bookings will look like VRL-7F3K/)).toBeInTheDocument();
    expect(calls.filter((c) => c.method === 'PUT').at(-1)?.body).toEqual({ prefix: 'VRL' });
  });

  it('is not shown to teachers', async () => {
    mockApi({
      ...signedIn(providerUser),
      'GET /api/admin/bookings': { body: noBookings },
    });
    renderAt(`${ADMIN}/bookings`);

    expect(await screen.findByRole('heading', { name: 'Bookings' })).toBeInTheDocument();
    expect(screen.queryByText(/Booking codes start with/)).not.toBeInTheDocument();
  });
});
