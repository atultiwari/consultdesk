import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, heldBooking, mockApi, ok, path, provider, renderAt, site, thesis } from '../support';

const slots = {
  timezone: 'Asia/Kolkata',
  from: '2026-10-05',
  to: '2026-10-13',
  slots: [
    { start: '2026-10-07T04:30:00Z', end: '2026-10-07T05:30:00Z' },
    { start: '2026-10-07T11:30:00Z', end: '2026-10-07T12:30:00Z' },
  ],
};

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-05T00:00:00Z'));
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    timeZone: 'Asia/Kolkata',
  } as Intl.ResolvedDateTimeFormatOptions);
  window.scrollTo = vi.fn();
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

async function fillDetails(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText('Full name'), 'Asha Placeholder');
  await user.type(screen.getByLabelText('Email'), 'asha@example.test');
  await user.type(screen.getByLabelText('WhatsApp number'), '+91 00000 00000');
  await user.type(screen.getByLabelText('What do you want to walk away with?'), 'Feedback');
  await user.click(screen.getByLabelText('No patient data'));
  await user.click(screen.getByRole('button', { name: 'Continue' }));
}

describe('booking flow', () => {
  it('books a time, validates details and lands on the status page', async () => {
    const calls = mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/demo': ok({ provider, services: [thesis] }),
      'GET /api/providers/demo/services/thesis/slots': ok(slots),
      'POST /api/bookings': ok(
        {
          ref: 'CD-7F3K',
          token: 'tok',
          status_url: 'https://x/b/CD-7F3K?t=tok',
          booking: heldBooking,
        },
        201,
      ),
      'GET /api/bookings/CD-7F3K': ok(heldBooking),
    });
    const user = userEvent.setup();
    renderAt('/p/demo/thesis');

    expect(
      await screen.findByRole('heading', { name: 'Thesis guidance', level: 1 }),
    ).toBeInTheDocument();
    expect(await screen.findByText(/Kolkata \(GMT\+5:30\)/)).toBeInTheDocument();
    expect(await screen.findByText('Morning')).toBeInTheDocument();
    expect(screen.getByText('Evening')).toBeInTheDocument();

    await user.click(screen.getByRole('radio', { name: '10:00 AM' }));
    await user.click(screen.getByRole('button', { name: 'Continue' }));

    // Client-side validation before anything is sent.
    await user.click(await screen.findByRole('button', { name: 'Continue' }));
    expect(await screen.findByText('Please enter your name.')).toBeInTheDocument();
    expect(screen.getByText('Please confirm this to continue.')).toBeInTheDocument();

    await fillDetails(user);
    expect(await screen.findByRole('heading', { name: 'Review & pay' })).toBeInTheDocument();
    expect(screen.getAllByText(/Wednesday 7 October, 10:00 AM/).length).toBeGreaterThan(0);

    await user.click(screen.getByRole('button', { name: 'Book and pay ₹2,999' }));

    await waitFor(() => expect(path()).toBe('/b/CD-7F3K?t=tok'));
    expect(await screen.findByText('CD-7F3K')).toBeInTheDocument();
    const posted = calls.find((c) => c.method === 'POST');
    expect(posted?.body).toEqual({
      provider: 'demo',
      service: 'thesis',
      start: '2026-10-07T04:30:00Z',
      payment_method: 'upi',
      customer: {
        name: 'Asha Placeholder',
        email: 'asha@example.test',
        phone: '+91 00000 00000',
        timezone: 'Asia/Kolkata',
      },
      answers: { goal: 'Feedback', consent: true },
      website: '',
    });
  });

  it('takes a coupon quietly tucked under the price', async () => {
    const calls = mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/demo': ok({ provider, services: [thesis] }),
      'GET /api/providers/demo/services/thesis/slots': ok(slots),
      'POST /api/coupons/check': (init) =>
        JSON.parse(String(init?.body)).code === 'WELCOME20'
          ? ok({
              code: 'WELCOME20',
              discount_minor: 59900,
              discount_display: '₹599',
              total_minor: 240000,
              total_display: '₹2,400',
            })
          : fail(422, 'validation_failed', 'Check the highlighted fields.', {
              code: 'That code isn’t valid for this session.',
            }),
      'POST /api/bookings': ok(
        {
          ref: 'CD-7F3K',
          token: 'tok',
          status_url: 'https://x/b/CD-7F3K?t=tok',
          booking: heldBooking,
        },
        201,
      ),
      'GET /api/bookings/CD-7F3K': ok(heldBooking),
    });
    const user = userEvent.setup();
    renderAt('/p/demo/thesis');

    await user.click(await screen.findByRole('radio', { name: '10:00 AM' }));
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await fillDetails(user);

    await user.click(await screen.findByRole('button', { name: 'Have a coupon?' }));
    await user.type(screen.getByLabelText('Coupon code'), 'NOPE');
    await user.click(screen.getByRole('button', { name: 'Apply' }));
    expect(await screen.findByText('That code isn’t valid for this session.')).toBeInTheDocument();

    await user.clear(screen.getByLabelText('Coupon code'));
    await user.type(screen.getByLabelText('Coupon code'), 'welcome20');
    await user.click(screen.getByRole('button', { name: 'Apply' }));
    expect((await screen.findByRole('button', { name: 'Remove' })).closest('p')).toHaveTextContent(
      'WELCOME20 applied: −₹599',
    );
    expect(
      calls.find(
        (c) => c.path === '/api/coupons/check' && (c.body as { code: string }).code === 'WELCOME20',
      )?.body,
    ).toMatchObject({
      provider: 'demo',
      service: 'thesis',
      email: 'asha@example.test',
    });

    await user.click(screen.getByRole('button', { name: 'Book and pay ₹2,400' }));
    await waitFor(() => expect(path()).toBe('/b/CD-7F3K?t=tok'));
    expect(calls.find((c) => c.path === '/api/bookings')?.body).toMatchObject({
      coupon: 'WELCOME20',
    });
  });

  it('goes back to the time step when the slot was just taken', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/demo': ok({ provider, services: [thesis] }),
      'GET /api/providers/demo/services/thesis/slots': ok(slots),
      'POST /api/bookings': fail(409, 'slot_unavailable', 'That time is no longer available.'),
    });
    const user = userEvent.setup();
    renderAt('/p/demo/thesis');

    await user.click(await screen.findByRole('radio', { name: '10:00 AM' }));
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await fillDetails(user);
    await user.click(await screen.findByRole('button', { name: 'Book and pay ₹2,999' }));

    expect(
      await screen.findByText(/That time is no longer available\. Please pick another time\./),
    ).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Choose a time' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
  });

  it('shows server field errors on the details step', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/demo': ok({ provider, services: [thesis] }),
      'GET /api/providers/demo/services/thesis/slots': ok(slots),
      'POST /api/bookings': fail(422, 'validation_failed', 'Please check the highlighted fields.', {
        'customer.email': 'This address cannot receive email.',
      }),
    });
    const user = userEvent.setup();
    renderAt('/p/demo/thesis');

    await user.click(await screen.findByRole('radio', { name: '10:00 AM' }));
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await fillDetails(user);
    await user.click(await screen.findByRole('button', { name: 'Book and pay ₹2,999' }));

    expect(await screen.findByText('This address cannot receive email.')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Your details' })).toBeInTheDocument();
  });

  it('explains approval for free sessions', async () => {
    const intro = {
      ...thesis,
      slug: 'intro',
      title: 'Intro call',
      price_minor: 0,
      price_display: 'Free',
      requires_approval: true,
      payment_methods: ['free'],
      questions: [],
    };
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/demo': ok({ provider, services: [intro] }),
      'GET /api/providers/demo/services/intro/slots': ok(slots),
    });
    const user = userEvent.setup();
    renderAt('/p/demo/intro');

    await user.click(await screen.findByRole('radio', { name: '10:00 AM' }));
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await user.type(screen.getByLabelText('Full name'), 'Asha');
    await user.type(screen.getByLabelText('Email'), 'asha@example.test');
    await user.type(screen.getByLabelText('WhatsApp number'), '+910000000000');
    await user.click(screen.getByRole('button', { name: 'Continue' }));

    expect(await screen.findByText('Needs approval')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Send request' })).toBeInTheDocument();
  });

  it('reports a session that does not exist', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/demo': ok({ provider, services: [thesis] }),
    });
    renderAt('/p/demo/nope');

    expect(await screen.findByText(/This session isn.t available/)).toBeInTheDocument();
  });
});
