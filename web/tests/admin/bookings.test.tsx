import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, path, renderAt } from '../support';
import { ADMIN, detail, row, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const page = (rows: unknown[], total = rows.length, pageNo = 1) => ({
  status: 200,
  body: { success: true, data: rows, error: null, meta: { total, page: pageNo, per_page: 25 } },
});

describe('admin bookings', () => {
  it('filters and searches through the address bar and pages through results', async () => {
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([]),
      'GET /api/admin/bookings': (_init, url) =>
        page(
          [{ ...row, id: Number(url.searchParams.get('page') ?? 1) }],
          30,
          Number(url.searchParams.get('page') ?? 1),
        ),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/bookings`);

    const table = await screen.findByRole('table', { name: 'Bookings' });
    expect(within(table).getByRole('link', { name: 'CD-7F3K' })).toHaveAttribute(
      'href',
      `${ADMIN}/bookings/1`,
    );
    expect(screen.getByText('1–25 of 30')).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText('Status'), 'awaiting_verification');
    await user.type(screen.getByLabelText('Search'), 'asha{Enter}');
    expect(path()).toBe(`${ADMIN}/bookings?status=awaiting_verification&q=asha`);
    expect(calls.at(-1)?.path).toBe(
      '/api/admin/bookings?status=awaiting_verification&q=asha&page=1&per_page=25',
    );

    await user.click(screen.getByRole('button', { name: 'Next page' }));
    expect(await screen.findByText('26–30 of 30')).toBeInTheDocument();
    expect(path()).toContain('page=2');
  });

  it('shows the details, answers and history, and asks twice before rejecting', async () => {
    let status = 'awaiting_verification';
    mockApi({
      ...signedIn(),
      'GET /api/admin/bookings/11': () =>
        ok({ ...detail, status, actions: status === 'rejected' ? [] : detail.actions }),
      'POST /api/admin/bookings/11/reject': () => {
        status = 'rejected';
        return ok({ ...detail, status, actions: [] });
      },
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/bookings/11`);

    expect(await screen.findByRole('heading', { name: /CD-7F3K/ })).toBeInTheDocument();
    expect(screen.getByText('Feedback on chapter 2')).toBeInTheDocument();
    expect(screen.getByText('+910000000000')).toBeInTheDocument();
    const history = screen.getByRole('list', { name: 'History' });
    expect(within(history).getAllByRole('listitem')).toHaveLength(2);
    expect(within(history).getByText(/UTR submitted/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Reject' }));
    expect(screen.getByText(/The customer will be emailed/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Yes, reject' }));

    expect(await screen.findByText('Rejected')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('shows why an action failed', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/bookings/11': ok({
        ...detail,
        status: 'confirmed',
        actions: ['complete', 'no-show', 'cancel'],
      }),
      'POST /api/admin/bookings/11/complete': fail(
        409,
        'session_not_started',
        'The session has not started yet.',
      ),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/bookings/11`);

    await user.click(await screen.findByRole('button', { name: 'Mark completed' }));

    expect(await screen.findByText('The session has not started yet.')).toBeInTheDocument();
  });

  it('says so when a booking is out of reach', async () => {
    mockApi({ ...signedIn(), 'GET /api/admin/bookings/99': fail(404, 'not_found', 'Not found.') });
    renderAt(`${ADMIN}/bookings/99`);

    expect(await screen.findByText(/couldn.t find that booking/)).toBeInTheDocument();
  });
});
