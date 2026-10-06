import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, heldBooking, mockApi, ok, path, provider, renderAt, site, thesis } from '../support';

const online = {
  ...heldBooking,
  payment_method: 'razorpay_link',
  hold_expires_at: '2026-10-05T00:30:00Z',
  payment: {
    method: 'razorpay_link',
    pay_url: 'https://rzp.io/l/placeholder',
    amount_display: '₹2,999',
  },
};

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-05T00:00:00Z'));
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    timeZone: 'Asia/Kolkata',
  } as Intl.ResolvedDateTimeFormatOptions);
  window.scrollTo = vi.fn();
  sessionStorage.clear();
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('paying online with Razorpay', () => {
  it('offers both ways to pay and sends the choice', async () => {
    const calls = mockApi({
      'GET /api/site': ok(site),
      'GET /api/providers/demo': ok({
        provider,
        services: [{ ...thesis, payment_methods: ['upi', 'razorpay_link'] }],
      }),
      'GET /api/providers/demo/services/thesis/slots': ok({
        timezone: 'Asia/Kolkata',
        from: '2026-10-05',
        to: '2026-10-13',
        slots: [{ start: '2026-10-07T04:30:00Z', end: '2026-10-07T05:30:00Z' }],
      }),
      'POST /api/bookings': ok(
        { ref: 'CD-7F3K', token: 'tok', status_url: 'x', booking: online },
        201,
      ),
      'GET /api/bookings/CD-7F3K': ok(online),
    });
    const user = userEvent.setup();
    renderAt('/p/demo/thesis');

    await user.click(await screen.findByRole('radio', { name: '10:00 AM' }));
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await user.type(await screen.findByLabelText('Full name'), 'Asha Placeholder');
    await user.type(screen.getByLabelText('Email'), 'asha@example.test');
    await user.type(screen.getByLabelText('WhatsApp number'), '+91 00000 00000');
    await user.type(screen.getByLabelText('What do you want to walk away with?'), 'Feedback');
    await user.click(screen.getByLabelText('No patient data'));
    await user.click(screen.getByRole('button', { name: 'Continue' }));

    await user.click(await screen.findByRole('radio', { name: /Pay online/ }));
    expect(screen.getByText(/held for 30 minutes/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Book and pay ₹2,999' }));

    await waitFor(() => expect(path()).toBe('/b/CD-7F3K?t=tok'));
    expect(
      (calls.find((c) => c.method === 'POST')?.body as { payment_method: string }).payment_method,
    ).toBe('razorpay_link');
    const pay = await screen.findByRole('link', { name: 'Pay ₹2,999 online' });
    expect(pay).toHaveAttribute('href', 'https://rzp.io/l/placeholder');
    pay.addEventListener('click', (e) => e.preventDefault());
    await user.click(pay);
    expect(sessionStorage.getItem('consultdesk-pay-CD-7F3K')).toBe('tok');
  });

  it('can ask again for the payment page if Razorpay was briefly down', async () => {
    const calls = mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': ok({ ...online, payment: { ...online.payment, pay_url: null } }),
      'POST /api/bookings/CD-7F3K/razorpay': ok(online),
    });
    const user = userEvent.setup();
    renderAt('/b/CD-7F3K?t=tok');

    await user.click(await screen.findByRole('button', { name: 'Get the payment page' }));

    expect(await screen.findByRole('link', { name: 'Pay ₹2,999 online' })).toBeInTheDocument();
    expect(calls.at(-1)?.body).toEqual({ token: 'tok' });
  });

  it('confirms on return and reopens the booking with the token this tab kept', async () => {
    sessionStorage.setItem('consultdesk-pay-CD-7F3K', 'tok');
    const calls = mockApi({
      'GET /api/site': ok(site),
      'POST /api/bookings/CD-7F3K/razorpay/return': ok({ ref: 'CD-7F3K', status: 'confirmed' }),
      'GET /api/bookings/CD-7F3K': ok({
        ...online,
        status: 'confirmed',
        payment: null,
        hold_expires_at: null,
      }),
    });
    renderAt(
      '/b/CD-7F3K?paid=1&razorpay_payment_id=pay_1&razorpay_payment_link_id=plink_1&razorpay_payment_link_reference_id=CD-7F3K&razorpay_payment_link_status=paid&razorpay_signature=sig',
    );

    await waitFor(() => expect(path()).toBe('/b/CD-7F3K?t=tok'));
    expect(await screen.findByText(/You.re booked/)).toBeInTheDocument();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({
      razorpay_payment_id: 'pay_1',
      razorpay_payment_link_id: 'plink_1',
      razorpay_payment_link_reference_id: 'CD-7F3K',
      razorpay_payment_link_status: 'paid',
      razorpay_signature: 'sig',
    });
  });

  it('still says so in another browser, without the token', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'POST /api/bookings/CD-7F3K/razorpay/return': ok({ ref: 'CD-7F3K', status: 'confirmed' }),
    });
    renderAt('/b/CD-7F3K?paid=1&razorpay_signature=sig');

    expect(await screen.findByText('Payment received — you’re booked')).toBeInTheDocument();
  });

  it('explains a payment that arrived after the hold ended', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'POST /api/bookings/CD-7F3K/razorpay/return': ok({ ref: 'CD-7F3K', status: 'expired' }),
    });
    renderAt('/b/CD-7F3K?paid=1&razorpay_signature=sig');

    expect(
      await screen.findByText('Your payment arrived after the slot was released'),
    ).toBeInTheDocument();
  });

  it('is honest when the return cannot be checked', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'POST /api/bookings/CD-7F3K/razorpay/return': fail(400, 'bad_request', 'Nope.'),
    });
    renderAt('/b/CD-7F3K?paid=1&razorpay_signature=forged');

    expect(await screen.findByText('We couldn’t confirm the payment here')).toBeInTheDocument();
  });
});
