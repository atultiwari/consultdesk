import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, heldBooking, mockApi, ok, renderAt, site } from '../support';

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-05T00:15:30Z'));
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('status page', () => {
  it('shows the slip, UPI details, the hold countdown and the single-use notice', async () => {
    mockApi({ 'GET /api/site': ok(site), 'GET /api/bookings/CD-7F3K': ok(heldBooking) });
    renderAt('/b/CD-7F3K?t=tok');

    expect(await screen.findByText('CD-7F3K')).toBeInTheDocument();
    expect(screen.getByText('Awaiting payment')).toBeInTheDocument();
    expect(screen.getByRole('timer')).toHaveTextContent('44:30');
    expect(screen.getByText('Pay once, for this booking only')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: /UPI QR code to pay ₹2,999/ })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Pay ₹2,999 in a UPI app' })).toHaveAttribute(
      'href',
      heldBooking.payment.upi_uri,
    );
    expect(screen.getByText('placeholder@upi')).toBeInTheDocument();
  });

  it('shows the coupon that lowered the fee', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': ok({
        ...heldBooking,
        coupon_code: 'WELCOME20',
        discount_minor: 59900,
        discount_display: '₹599',
      }),
    });
    renderAt('/b/CD-7F3K?t=tok');

    expect(await screen.findByText('WELCOME20')).toBeInTheDocument();
    expect(screen.getByText(/saved you ₹599/)).toBeInTheDocument();
  });

  it('warns in the last ten minutes', async () => {
    vi.setSystemTime(new Date('2026-10-05T00:55:00Z'));
    mockApi({ 'GET /api/site': ok(site), 'GET /api/bookings/CD-7F3K': ok(heldBooking) });
    renderAt('/b/CD-7F3K?t=tok');

    expect(await screen.findByText('Less than 10 minutes left')).toBeInTheDocument();
  });

  it('validates and submits the UTR', async () => {
    const awaiting = {
      ...heldBooking,
      status: 'awaiting_verification',
      utr: '412345678901',
      payment: { ...heldBooking.payment, can_submit_utr: false },
    };
    const calls = mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': ok(heldBooking),
      'POST /api/bookings/CD-7F3K/utr': ok(awaiting),
    });
    const user = userEvent.setup();
    renderAt('/b/CD-7F3K?t=tok');

    const input = await screen.findByLabelText('UPI reference (UTR)');
    await user.type(input, '1234');
    await user.click(screen.getByRole('button', { name: /submit UTR/ }));
    expect(await screen.findByText(/12-digit number/)).toBeInTheDocument();

    await user.clear(input);
    await user.type(input, '4123 4567 8901');
    await user.click(screen.getByRole('button', { name: /submit UTR/ }));

    expect(await screen.findByText(/Payment received/)).toBeInTheDocument();
    expect(screen.getByText('Verifying payment')).toBeInTheDocument();
    expect(calls.find((c) => c.method === 'POST')?.body).toEqual({
      token: 'tok',
      utr: '412345678901',
    });
  });

  it('shows a duplicate UTR error from the server', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': ok(heldBooking),
      'POST /api/bookings/CD-7F3K/utr': fail(
        409,
        'duplicate_utr',
        'This UTR has already been used for a booking.',
      ),
    });
    const user = userEvent.setup();
    renderAt('/b/CD-7F3K?t=tok');

    await user.type(await screen.findByLabelText('UPI reference (UTR)'), '412345678901');
    await user.click(screen.getByRole('button', { name: /submit UTR/ }));

    expect(
      await screen.findByText('This UTR has already been used for a booking.'),
    ).toBeInTheDocument();
  });

  it('tells customers not to pay for an expired hold', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': ok({
        ...heldBooking,
        status: 'expired',
        payment: null,
        hold_expires_at: null,
      }),
    });
    renderAt('/b/CD-7F3K?t=tok');

    expect(await screen.findByText('This hold has expired')).toBeInTheDocument();
    expect(screen.getByText(/Please don.t pay for this booking/)).toBeInTheDocument();
    expect(screen.queryByLabelText('UPI reference (UTR)')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Book a new time' })).toHaveAttribute(
      'href',
      '/p/demo',
    );
  });

  it('shows the Meet link once confirmed', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': ok({
        ...heldBooking,
        status: 'confirmed',
        payment: null,
        hold_expires_at: null,
        meet_url: 'https://meet.example.test/abc',
      }),
    });
    renderAt('/b/CD-7F3K?t=tok');

    expect(await screen.findByText(/You.re booked/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Join the video call' })).toHaveAttribute(
      'href',
      'https://meet.example.test/abc',
    );
  });

  it('refreshes when the countdown reaches zero', async () => {
    vi.setSystemTime(new Date('2026-10-05T00:59:59Z'));
    let served = 0;
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': () =>
        served++ === 0 ? ok(heldBooking) : ok({ ...heldBooking, status: 'expired', payment: null }),
    });
    renderAt('/b/CD-7F3K?t=tok');
    await screen.findByText('Awaiting payment');

    await act(async () => {
      vi.setSystemTime(new Date('2026-10-05T01:00:01Z'));
      await new Promise((resolve) => setTimeout(resolve, 1100));
    });

    expect(await screen.findByText('This hold has expired')).toBeInTheDocument();
  });

  it('stops offering payment the moment the hold runs out, before the server catches up', async () => {
    vi.setSystemTime(new Date('2026-10-05T00:59:59Z'));
    mockApi({ 'GET /api/site': ok(site), 'GET /api/bookings/CD-7F3K': ok(heldBooking) });
    renderAt('/b/CD-7F3K?t=tok');
    await screen.findByLabelText('UPI reference (UTR)');

    await act(async () => {
      vi.setSystemTime(new Date('2026-10-05T01:00:01Z'));
      await new Promise((resolve) => setTimeout(resolve, 1100));
    });

    expect(screen.getByText(/Do not pay for this booking/)).toBeInTheDocument();
    expect(screen.queryByLabelText('UPI reference (UTR)')).not.toBeInTheDocument();
    expect(screen.queryByRole('img', { name: /UPI QR code/ })).not.toBeInTheDocument();
  });

  it('explains a reused UTR and offers WhatsApp with the reference', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': ok(heldBooking),
      'POST /api/bookings/CD-7F3K/utr': fail(
        409,
        'duplicate_utr',
        'This UTR has already been used for a booking.',
      ),
    });
    const user = userEvent.setup();
    renderAt('/b/CD-7F3K?t=tok');

    await user.type(await screen.findByLabelText('UPI reference (UTR)'), '412345678901');
    await user.click(screen.getByRole('button', { name: /submit UTR/ }));

    expect(await screen.findByText(/can.t be reused for another booking/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Message .* on WhatsApp/ })).toHaveAttribute(
      'href',
      heldBooking.payment.whatsapp_url,
    );
  });

  it('keeps showing the booking when a background refresh fails', async () => {
    let served = 0;
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': () =>
        served++ === 0 ? ok(heldBooking) : fail(503, 'server_error', 'Down'),
    });
    const { rerender } = renderAt('/b/CD-7F3K?t=tok');
    await screen.findByText('CD-7F3K');

    await act(async () => {
      vi.setSystemTime(new Date('2026-10-05T01:00:01Z'));
      await new Promise((resolve) => setTimeout(resolve, 1100));
    });
    rerender(<></>);

    expect(screen.queryByText(/couldn.t find that booking/)).not.toBeInTheDocument();
  });

  it('never renders unsafe links from the API', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': ok({
        ...heldBooking,
        status: 'confirmed',
        payment: null,
        meet_url: 'javascript:alert(1)',
      }),
    });
    renderAt('/b/CD-7F3K?t=tok');

    expect(await screen.findByText(/You.re booked/)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Join the video call' })).not.toBeInTheDocument();
  });

  it('asks for the emailed link when the token is wrong', async () => {
    mockApi({
      'GET /api/site': ok(site),
      'GET /api/bookings/CD-7F3K': fail(404, 'not_found', 'Booking not found.'),
    });
    renderAt('/b/CD-7F3K?t=wrong');

    expect(await screen.findByText(/couldn.t find that booking/)).toBeInTheDocument();
  });
});
