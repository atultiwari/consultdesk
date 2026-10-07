import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, heldBooking, mockApi, ok, path, renderAt, site } from '../support';

afterEach(() => vi.unstubAllGlobals());

const upcoming = {
  ...heldBooking,
  status_url: 'https://book.example.test/b/CD-7F3K?t=tok',
  can_cancel: true,
};
const past = {
  ...heldBooking,
  ref: 'CD-2PAS',
  status: 'completed',
  status_url: 'https://book.example.test/b/CD-2PAS?t=tok2',
  can_cancel: false,
};

describe('my bookings', () => {
  it('emails a sign-in link without saying whether the address has bookings', async () => {
    const calls = mockApi({
      'GET /api/site': ok(site),
      'GET /api/my/bookings': fail(
        401,
        'unauthenticated',
        'Please sign in with the link we email you.',
      ),
      'POST /api/my/link': ok({ ok: true }),
    });
    const user = userEvent.setup();
    renderAt('/my-bookings');

    await user.type(await screen.findByLabelText('Email'), 'asha@example.test');
    await user.click(screen.getByRole('button', { name: 'Email me a link' }));

    expect(
      await screen.findByText(/If there are bookings for asha@example.test/),
    ).toBeInTheDocument();
    expect(calls.find((c) => c.path === '/api/my/link')?.body).toEqual({
      email: 'asha@example.test',
    });
  });

  it('signs in from the emailed link, lists bookings and cancels an unpaid one', async () => {
    let signedIn = false;
    let cancelled = false;
    const calls = mockApi({
      'GET /api/site': ok(site),
      'POST /api/my/session': () => {
        signedIn = true;
        return ok({ email: 'asha@example.test' });
      },
      'GET /api/my/bookings': () =>
        signedIn
          ? ok({ email: 'asha@example.test', upcoming: cancelled ? [] : [upcoming], past: [past] })
          : fail(401, 'unauthenticated', 'Please sign in.'),
      'POST /api/my/bookings/CD-7F3K/cancel': () => {
        cancelled = true;
        return ok({
          email: 'asha@example.test',
          upcoming: [],
          past: [past, { ...upcoming, status: 'cancelled', can_cancel: false }],
        });
      },
    });
    const user = userEvent.setup();
    renderAt('/my-bookings?token=link-token-placeholder');

    expect(await screen.findByRole('heading', { name: 'Your bookings' })).toBeInTheDocument();
    await waitFor(() => expect(path()).toBe('/my-bookings'));
    expect(calls.find((c) => c.path === '/api/my/session')?.body).toEqual({
      token: 'link-token-placeholder',
    });

    const card = screen.getAllByText('CD-7F3K')[0].closest('li') as HTMLElement;
    expect(within(card).getByRole('link', { name: /Open/ })).toHaveAttribute(
      'href',
      '/b/CD-7F3K?t=tok',
    );
    await user.click(within(card).getByRole('button', { name: 'Cancel booking' }));
    await user.click(within(card).getByRole('button', { name: 'Yes, cancel it' }));

    expect(await screen.findByText('No upcoming bookings.')).toBeInTheDocument();
  });

  it('explains an expired link', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'POST /api/my/session': fail(
        404,
        'not_found',
        'This link has expired or was already used. Ask for a new one.',
      ),
      'GET /api/my/bookings': fail(401, 'unauthenticated', 'Please sign in.'),
    });
    renderAt('/my-bookings?token=old');

    expect(await screen.findByText(/This link has expired/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Email me a link' })).toBeInTheDocument();
  });
});
