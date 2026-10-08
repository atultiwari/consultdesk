import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, renderAt } from '../support';
import { ADMIN, demoProvider, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const blank = {
  methods: { upi_enabled: true, razorpay_enabled: true },
  razorpay: {
    live: false,
    mode: 'test',
    configured: false,
    key_id: null,
    has_webhook_secret: false,
    webhook_url: 'https://book.example.test/api/webhooks/razorpay',
    source: null,
    env_key_id: null,
    accounts: { test: null, live: null },
  },
  overrides: [],
};
const keyId = `rzp_test_${'A'.repeat(14)}`;
const liveKeyId = `rzp_live_${'L'.repeat(14)}`;
const testAccount = { key_id: keyId, source: 'settings', has_webhook_secret: true };
const liveAccount = { key_id: liveKeyId, source: 'settings', has_webhook_secret: true };
const configured = {
  ...blank,
  razorpay: {
    ...blank.razorpay,
    configured: true,
    key_id: keyId,
    has_webhook_secret: true,
    source: 'settings',
    accounts: { test: testAccount, live: null },
  },
};
const bothKeys = {
  ...configured,
  razorpay: { ...configured.razorpay, accounts: { test: testAccount, live: liveAccount } },
};
const goneLive = {
  ...bothKeys,
  overrides: [
    {
      provider_id: 7,
      provider_name: 'Dr. Demo',
      key_id: `rzp_test_${'P'.repeat(14)}`,
      mode: 'test',
      has_webhook_secret: true,
    },
  ],
  razorpay: {
    ...bothKeys.razorpay,
    live: true,
    mode: 'live',
    key_id: liveKeyId,
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
              key_secret: 'Paste the Key Secret exactly as Razorpay showed it.',
            })
          : ok({ ...configured, razorpay: { ...configured.razorpay, webhook_secret: secret } }),
      'POST /api/admin/payments/razorpay/check': ok(configured),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/payments`);

    const section = await screen.findByRole('region', { name: 'Test keys' });
    await user.type(within(section).getByLabelText('Key ID'), liveKeyId);
    await user.type(within(section).getByLabelText('Key Secret'), crypto.randomUUID());
    await user.click(within(section).getByRole('button', { name: 'Save test keys' }));
    expect(await within(section).findByText(/starts with rzp_test_/)).toBeInTheDocument();
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);

    await user.clear(within(section).getByLabelText('Key ID'));
    await user.type(within(section).getByLabelText('Key ID'), keyId);
    await user.click(within(section).getByRole('button', { name: 'Save test keys' }));
    expect(await within(section).findByText(/exactly as Razorpay showed it/)).toBeInTheDocument();
    await user.click(within(section).getByRole('button', { name: 'Save test keys' }));

    expect(await within(section).findByText(secret)).toBeInTheDocument();
    expect(within(section).getByText('In use')).toBeInTheDocument();
    expect(
      within(section).getByText('https://book.example.test/api/webhooks/razorpay'),
    ).toBeInTheDocument();
    expect((calls.find((c) => c.method === 'PUT')?.body as { key_id: string }).key_id).toBe(keyId);

    await user.click(within(section).getByRole('button', { name: 'Check connection' }));
    expect(await within(section).findByText('Razorpay accepted these keys.')).toBeInTheDocument();
    expect(calls.find((c) => c.path.startsWith('/api/admin/payments/razorpay/check'))?.path).toBe(
      '/api/admin/payments/razorpay/check?mode=test',
    );
  });

  it('shows test mode and can only go live once live keys are saved', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/payments': ok(configured),
    });
    renderAt(`${ADMIN}/payments`);

    const mode = await screen.findByRole('region', { name: 'Test or live payments' });
    expect(within(mode).getByText(/Test mode: no real money/)).toBeInTheDocument();
    expect(within(mode).getByRole('switch', { name: 'Live payments' })).toBeDisabled();
    expect(within(mode).getByText(/Save your live keys below first/)).toBeInTheDocument();
  });

  it('goes live after a confirmation, and back to test', async () => {
    let state: object = bothKeys;
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/payments': () => ok(state),
      'PUT /api/admin/payments/mode': (init) => {
        state = (JSON.parse(String(init?.body)) as { live: boolean }).live ? goneLive : bothKeys;
        return ok(state);
      },
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/payments`);

    const mode = await screen.findByRole('region', { name: 'Test or live payments' });
    const toggle = within(mode).getByRole('switch', { name: 'Live payments' });
    expect(toggle).toHaveAttribute('aria-checked', 'false');
    await user.click(toggle);
    expect(within(mode).getByText(/charged real money/)).toBeInTheDocument();
    expect(calls.some((c) => c.method === 'PUT')).toBe(false);
    await user.click(within(mode).getByRole('button', { name: 'Yes, take real payments' }));

    expect(await within(mode).findByText(/Live: customers pay real money/)).toBeInTheDocument();
    expect(toggle).toHaveAttribute('aria-checked', 'true');
    expect(
      within(screen.getByRole('region', { name: 'Live keys' })).getByText('In use'),
    ).toBeInTheDocument();
    expect(screen.getByText(/Not used in live mode/)).toBeInTheDocument();

    await user.click(toggle);
    await user.click(within(mode).getByRole('button', { name: 'Yes, switch to test mode' }));
    expect(await within(mode).findByText(/Test mode: no real money/)).toBeInTheDocument();
    expect(calls.filter((c) => c.method === 'PUT').map((c) => c.body)).toEqual([
      { live: true },
      { live: false },
    ]);
  });

  it('removes one set of keys at a time', async () => {
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/payments': ok(goneLive),
      'DELETE /api/admin/payments/razorpay': ok({
        ...goneLive,
        razorpay: { ...goneLive.razorpay, accounts: { test: null, live: liveAccount } },
      }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/payments`);

    const test = await screen.findByRole('region', { name: 'Test keys' });
    await user.click(within(test).getByRole('button', { name: 'Remove keys' }));
    await user.click(within(test).getByRole('button', { name: 'Yes, remove keys' }));

    expect(await within(test).findByLabelText('Key ID')).toBeInTheDocument();
    expect(calls.find((c) => c.method === 'DELETE')?.path).toBe(
      '/api/admin/payments/razorpay?mode=test',
    );
  });

  it('says when the keys come from the server’s .env, and what removing saved keys does', async () => {
    const envKey = `rzp_test_${'E'.repeat(14)}`;
    mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/payments': ok({
        ...configured,
        razorpay: {
          ...configured.razorpay,
          key_id: envKey,
          source: 'env',
          env_key_id: envKey,
          accounts: {
            test: { key_id: envKey, source: 'env', has_webhook_secret: true },
            live: null,
          },
        },
      }),
    });
    renderAt(`${ADMIN}/payments`);

    expect(await screen.findByText(/from the server’s settings/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Remove keys' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'New webhook secret' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Use other keys' })).toBeInTheDocument();
  });

  it('offers online payment on every eligible session at once', async () => {
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/providers': ok([demoProvider]),
      'GET /api/admin/payments': ok(configured),
      'POST /api/admin/payments/razorpay/offer-everywhere': ok({ sessions_updated: 5 }),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/payments`);

    await user.click(
      await screen.findByRole('button', { name: 'Offer online payment on every paid session' }),
    );

    expect(await screen.findByText('Online payment added to 5 sessions.')).toBeInTheDocument();
    expect(calls.some((c) => c.path === '/api/admin/payments/razorpay/offer-everywhere')).toBe(
      true,
    );
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
