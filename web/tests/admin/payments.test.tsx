import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, renderAt } from '../support';
import { ADMIN, demoProvider, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const blank = {
  methods: { upi_enabled: true, razorpay_enabled: true },
  razorpay: {
    configured: false,
    mode: null,
    key_id: null,
    has_webhook_secret: false,
    webhook_url: 'https://book.example.test/api/webhooks/razorpay',
    live_allowed: false,
  },
  overrides: [],
};
const keyId = `rzp_test_${'A'.repeat(14)}`;
const configured = {
  ...blank,
  razorpay: {
    ...blank.razorpay,
    configured: true,
    mode: 'test',
    key_id: keyId,
    has_webhook_secret: true,
  },
};

describe('payments settings', () => {
  it('switches ways to pay on and off', async () => {
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/payments': ok(blank),
      'PUT /api/admin/payments/methods': (init) =>
        ok({ ...blank, methods: JSON.parse(String(init?.body)) }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/payments`);

    await user.click(await screen.findByLabelText(/^UPI to the teacher/));
    await user.click(screen.getByRole('button', { name: 'Save ways to pay' }));

    expect(await screen.findByText('Saved.')).toBeInTheDocument();
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({
      upi_enabled: false,
      razorpay_enabled: true,
    });
  });

  it('saves test keys and shows the webhook secret once', async () => {
    const secret = crypto.randomUUID();
    let attempt = 0;
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/payments': ok(blank),
      'PUT /api/admin/payments/razorpay': () =>
        attempt++ === 0
          ? fail(422, 'validation_failed', 'Check the fields.', {
              key_id: 'Only Test Mode keys (rzp_test_…) can be used for now.',
            })
          : ok({ ...configured, razorpay: { ...configured.razorpay, webhook_secret: secret } }),
      'POST /api/admin/payments/razorpay/check': ok(configured),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/payments`);

    const section = await screen.findByRole('region', { name: /organisation account/ });
    await user.type(within(section).getByLabelText('Key ID'), 'rzp_live_x');
    await user.type(within(section).getByLabelText('Key Secret'), crypto.randomUUID());
    await user.click(within(section).getByRole('button', { name: 'Save keys' }));
    expect(await within(section).findByText(/Only Test Mode keys/)).toBeInTheDocument();

    await user.clear(within(section).getByLabelText('Key ID'));
    await user.type(within(section).getByLabelText('Key ID'), keyId);
    await user.click(within(section).getByRole('button', { name: 'Save keys' }));

    expect(await within(section).findByText('Test mode')).toBeInTheDocument();
    expect(within(section).getByText(secret)).toBeInTheDocument();
    expect(
      within(section).getByText('https://book.example.test/api/webhooks/razorpay'),
    ).toBeInTheDocument();
    expect((calls.find((c) => c.method === 'PUT')?.body as { key_id: string }).key_id).toBe(
      'rzp_live_x',
    );

    await user.click(within(section).getByRole('button', { name: 'Check connection' }));
    expect(await within(section).findByText('Razorpay accepted these keys.')).toBeInTheDocument();
  });

  it('gives a teacher their own account', async () => {
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/payments': () => ok(configured),
      'PUT /api/admin/providers/7/razorpay': ok({
        provider_id: 7,
        key_id: keyId,
        webhook_secret: 'teacher-webhook-secret',
      }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/payments`);

    const section = await screen.findByRole('region', { name: /their own Razorpay account/ });
    await user.selectOptions(within(section).getByLabelText('Teacher'), '7');
    await user.type(within(section).getByLabelText('Key ID'), keyId);
    await user.type(within(section).getByLabelText('Key Secret'), crypto.randomUUID());
    await user.click(within(section).getByRole('button', { name: 'Save their keys' }));

    expect(await within(section).findByText('teacher-webhook-secret')).toBeInTheDocument();
    expect((calls.find((c) => c.method === 'PUT')?.body as { key_id: string }).key_id).toBe(keyId);
  });
});
