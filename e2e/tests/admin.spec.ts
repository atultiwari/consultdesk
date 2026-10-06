import { randomBytes } from 'node:crypto';
import { expect, test, type Page } from '@playwright/test';
import { resetRateLimits, sql, upsertAdmin } from '../helpers/backend';

const ADMIN_PATH = process.env.E2E_ADMIN_PATH ?? 'desk-local-dev';
const EMAIL = 'e2e-owner@example.test';
// A fresh throwaway password every run; it only ever exists in this test database.
const PASSWORD = randomBytes(18).toString('base64url');

test.beforeAll(() => upsertAdmin(EMAIL, PASSWORD));
test.beforeEach(() => resetRateLimits());

async function signIn(page: Page): Promise<void> {
  await page.goto(`/${ADMIN_PATH}`);
  await page.getByLabel('Email').fill(EMAIL);
  await page.getByLabel('Password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
}

/** A UPI booking with a submitted UTR, created through the public API. */
async function bookingAwaitingVerification(page: Page): Promise<string> {
  const slots = await page.request.get('/api/providers/demo/services/research-guidance/slots');
  const { data } = (await slots.json()) as {
    data: { slots: { start: string }[] };
  };
  const start = data.slots.at(-1 - (Date.now() % 5))?.start ?? '';
  const created = await page.request.post('/api/bookings', {
    data: {
      provider: 'demo',
      service: 'research-guidance',
      start,
      payment_method: 'upi',
      customer: {
        name: 'Admin E2E Placeholder',
        email: `admin-e2e-${Date.now()}@example.test`,
        phone: '+910000000000',
      },
      answers: {
        role: 'Researcher',
        goal: 'A sanity check of my analysis plan.',
        stage: 'Analysis',
        no_patient_data: true,
      },
    },
  });
  expect(created.status(), await created.text()).toBe(201);
  const { data: booking } = (await created.json()) as {
    data: { ref: string; token: string };
  };
  const utr = `8${String(Date.now()).slice(-11)}`;
  const submitted = await page.request.post(`/api/bookings/${booking.ref}/utr`, {
    data: { token: booking.token, utr },
  });
  expect(submitted.status()).toBe(200);
  return booking.ref;
}

test('the secret path is the only way in', async ({ page }) => {
  await page.goto('/desk-not-the-admin-path');
  await expect(page.getByRole('heading', { name: /This page doesn.t exist/ })).toBeVisible();
});

test('sign in → confirm a UPI payment from the dashboard → the customer sees it confirmed', async ({
  page,
}) => {
  const ref = await bookingAwaitingVerification(page);
  await signIn(page);

  const queue = page.getByRole('region', { name: /Payments to verify/ });
  await queue.getByRole('button', { name: `Confirm ${ref}` }).click();
  await expect(queue.getByRole('link', { name: ref })).toHaveCount(0);

  expect(sql('SELECT status FROM bookings WHERE ref = ?', [ref])).toBe('confirmed');
  expect(
    sql(
      "SELECT COUNT(*) FROM audit_log a JOIN bookings b ON b.id = a.entity_id WHERE a.entity_type = 'booking' AND a.action = 'booking.confirmed' AND a.actor_type = 'user' AND b.ref = ?",
      [ref],
    ),
  ).toBe('1');
});

test('reject asks first, then the history records who did it', async ({ page }) => {
  const ref = await bookingAwaitingVerification(page);
  await signIn(page);

  await page.getByRole('link', { name: 'Bookings' }).click();
  await page.getByLabel('Search').fill(ref);
  await page.getByLabel('Search').press('Enter');
  await page.getByRole('link', { name: ref }).click();

  await page.getByRole('button', { name: 'Reject' }).click();
  await expect(page.getByText(/The customer will be emailed/)).toBeVisible();
  await page.getByRole('button', { name: 'Yes, reject' }).click();

  await expect(page.locator('.page__head .badge')).toHaveText('Rejected');
  await expect(page.getByRole('list', { name: 'History' })).toContainText(`by ${EMAIL}`);
});

test('signing out ends the session', async ({ page }) => {
  await signIn(page);
  await page.getByRole('button', { name: /Account/ }).click();
  await page.getByRole('menuitem', { name: 'Sign out', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();

  const me = await page.request.get('/api/admin/me');
  expect(me.status()).toBe(401);
});
