import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, renderAt } from '../support';
import { ADMIN, demoProvider, detail, signedIn, thesisService } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const providerRoutes = {
  'GET /api/admin/providers': ok([demoProvider]),
  'GET /api/admin/providers/7/services': ok([thesisService]),
  'GET /api/admin/providers/7/availability': ok([]),
};

describe('new sessions', () => {
  it('creates a free session that takes only free bookings, and shows field errors', async () => {
    let attempt = 0;
    const calls = mockApi({
      ...signedIn(),
      ...providerRoutes,
      'POST /api/admin/providers/7/services': (init) =>
        attempt++ === 0
          ? fail(422, 'validation_failed', 'Check the highlighted fields.', {
              slug: 'Another session already uses this address.',
            })
          : ok({ ...thesisService, id: 22, ...JSON.parse(String(init?.body)) }, 201),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7?tab=sessions`);

    await user.click(await screen.findByRole('button', { name: 'Add session' }));
    const editor = screen.getByRole('dialog', { name: 'New session' });
    await user.type(within(editor).getByLabelText('Title'), 'Intro call');
    expect(within(editor).getByLabelText('Web address')).toHaveValue('intro-call');
    await user.clear(within(editor).getByLabelText('Price (₹)'));
    await user.type(within(editor).getByLabelText('Price (₹)'), '0');
    expect(within(editor).queryByText('Customers pay by')).not.toBeInTheDocument();
    await user.click(within(editor).getByLabelText('I approve each booking myself'));
    await user.click(within(editor).getByRole('button', { name: 'Save session' }));

    expect(
      await within(editor).findByText('Another session already uses this address.'),
    ).toBeInTheDocument();
    expect(calls.at(-1)?.body).toMatchObject({
      slug: 'intro-call',
      price_minor: 0,
      payment_methods: ['free'],
      requires_approval: true,
    });

    await user.click(within(editor).getByRole('button', { name: 'Save session' }));
    expect(await screen.findByText('Intro call')).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('closes on Escape, keeps Tab inside, and returns focus', async () => {
    mockApi({ ...signedIn(), ...providerRoutes });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7?tab=sessions`);

    const opener = await screen.findByRole('button', { name: 'Add session' });
    await user.click(opener);
    const editor = screen.getByRole('dialog', { name: 'New session' });
    const close = within(editor).getByRole('button', { name: 'Close' });
    expect(close).toHaveFocus();
    await user.tab({ shift: true });
    expect(within(editor).getByRole('button', { name: 'Cancel' })).toHaveFocus();
    await user.tab();
    expect(close).toHaveFocus();

    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(opener).toHaveFocus();
  });
});

describe('weekly hours and rules validation', () => {
  it('will not save a window that ends before it starts, and shows server errors per window', async () => {
    const calls = mockApi({
      ...signedIn(),
      ...providerRoutes,
      'PUT /api/admin/providers/7/availability': fail(
        422,
        'validation_failed',
        'Check the highlighted fields.',
        {
          'rules.0': 'That session belongs to another provider.',
        },
      ),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7?tab=hours`);

    const monday = await screen.findByRole('group', { name: 'Monday' });
    await user.click(within(monday).getByRole('button', { name: 'Add hours on Monday' }));
    const until = within(monday).getByLabelText('Monday until');
    await user.clear(until);
    await user.type(until, '08:00');
    expect(within(monday).getByText('The end must be after the start.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save hours' })).toBeDisabled();

    await user.clear(until);
    await user.type(until, '12:00');
    await user.selectOptions(within(monday).getByLabelText(/applies to/), String(thesisService.id));
    await user.click(screen.getByRole('button', { name: 'Save hours' }));

    expect(
      await within(monday).findByText('That session belongs to another provider.'),
    ).toBeInTheDocument();
    expect(calls.at(-1)?.body).toEqual({
      rules: [{ weekday: 1, start: '09:00', end: '12:00', service_id: 21 }],
    });
  });

  it('shows rule errors next to the rule', async () => {
    mockApi({
      ...signedIn(),
      ...providerRoutes,
      'PATCH /api/admin/providers/7': fail(
        422,
        'validation_failed',
        'Check the highlighted fields.',
        {
          'rules.slot_interval': 'Must be between 5 and 1440.',
        },
      ),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/providers/7?tab=rules`);

    await user.click(await screen.findByRole('button', { name: 'Save rules' }));
    expect(await screen.findByText('Must be between 5 and 1440.')).toBeInTheDocument();
    expect(screen.getByLabelText('Start times every')).toHaveAttribute('aria-invalid', 'true');
  });
});

describe('blocked times for staff', () => {
  it('closes the whole organisation for part of a day', async () => {
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/blocked': ok([
        {
          id: 9,
          provider_id: null,
          provider_name: null,
          start: '2026-10-20T03:30:00Z',
          end: '2026-10-20T07:30:00Z',
          all_day: false,
          reason: null,
        },
      ]),
      'POST /api/admin/blocked': ok({ id: 10 }, 201),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/blocked`);

    expect(await screen.findByText(/Everyone/, { selector: '.cell-sub' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Remove / })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Block' }));
    expect(screen.getByText('Choose the first day.')).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText('For'), '');
    await user.type(screen.getByLabelText('From'), '2026-10-21');
    await user.click(screen.getByLabelText('Whole days'));
    await user.clear(screen.getByLabelText('Starting at'));
    await user.type(screen.getByLabelText('Starting at'), '13:00');
    await user.click(screen.getByRole('button', { name: 'Block' }));

    const body = calls.find((c) => c.method === 'POST')?.body as Record<string, unknown>;
    expect(body).toMatchObject({ provider_id: null, all_day: false, reason: null });
    expect(new Date(String(body.end)).getTime() - new Date(String(body.start)).getTime()).toBe(
      4 * 3600_000,
    );
  });
});

describe('booking detail variants', () => {
  it('shows the Razorpay payment and when it needs refunding', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/bookings/11': ok({
        ...detail,
        payment_method: 'razorpay_link',
        utr: null,
        status: 'expired',
        gateway_payment_id: 'pay_placeholder1',
        actions: [],
      }),
    });
    renderAt(`${ADMIN}/bookings/11`);

    expect(await screen.findByText('pay_placeholder1')).toBeInTheDocument();
    expect(screen.getByText(/refund it from the Razorpay Dashboard/)).toBeInTheDocument();
  });

  it('shows ticked boxes, the call link, the customer’s own time and Telegram actions', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/bookings/11': ok({
        ...detail,
        status: 'confirmed',
        customer: { ...detail.customer, timezone: 'America/New_York' },
        answers: [{ id: 'consent', label: 'No patient data', value: true }],
        meet_url: 'https://meet.example.test/abc',
        history: [
          {
            action: 'booking.confirmed',
            actor_type: 'telegram',
            actor: 'Dr. Demo',
            data: {},
            at: '2026-10-05T01:00:00Z',
          },
        ],
        actions: ['complete', 'no-show', 'cancel'],
      }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/bookings/11`);

    expect(await screen.findByText('Yes')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Join the call' })).toHaveAttribute(
      'href',
      'https://meet.example.test/abc',
    );
    expect(screen.getByText(/for the customer/)).toBeInTheDocument();
    expect(screen.getByText(/via Telegram/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Mark no-show' }));
    await user.click(screen.getByRole('button', { name: 'Keep it' }));
    expect(screen.getByRole('button', { name: 'Mark no-show' })).toBeInTheDocument();
  });
});
