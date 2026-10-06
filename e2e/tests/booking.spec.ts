import { expect, test, type Page } from '@playwright/test';
import { resetRateLimits, sql } from '../helpers/backend';

const WEBHOOK_SECRET = process.env.TELEGRAM_WEBHOOK_SECRET;
const PROVIDER_CHAT = '7770001';

test.beforeEach(() => resetRateLimits());

async function bookAndSubmitUtr(page: Page): Promise<string> {
  const email = `e2e-${Date.now()}@example.test`;
  const utr = `9${String(Date.now()).slice(-11)}`;

  await page.goto('/p/demo');
  await page.getByRole('link', { name: /ML project and code review/ }).click();
  await page.locator('.slot').first().click();
  await page.getByRole('button', { name: 'Continue' }).click();

  await page.getByLabel('Full name').fill('E2E Placeholder');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('WhatsApp number').fill('+91 00000 00000');
  await page.getByLabel('Repository link').fill('https://example.test/placeholder-repo');
  await page.getByLabel('What the model does').fill('Classifies placeholder images.');
  await page.getByLabel(/identifiable patient data/).check();
  await page.getByRole('button', { name: 'Continue' }).click();

  await page.getByRole('button', { name: /Book and pay/ }).click();
  await expect(page).toHaveURL(/\/b\/CD-[2-9A-Z]{4}\?t=/);
  await expect(page.getByText('Awaiting payment')).toBeVisible();
  await expect(page.getByText('Pay once, for this booking only')).toBeVisible();
  const ref = (await page.locator('.slip__ref').textContent())?.trim() ?? '';

  await page.getByLabel('UPI reference (UTR)').fill(utr);
  await page.getByRole('button', { name: /submit UTR/ }).click();
  await expect(page.getByText('Verifying payment')).toBeVisible();

  return ref;
}

test('book → pay by UPI → submit UTR → awaiting verification', async ({ page }) => {
  await bookAndSubmitUtr(page);
});

test('the provider confirms in Telegram → the status page shows confirmed', async ({ page, request }) => {
  test.skip(!WEBHOOK_SECRET, 'Set TELEGRAM_WEBHOOK_SECRET (and configure the API with it) to test confirmation.');
  const ref = await bookAndSubmitUtr(page);

  // The provider taps ✅ Confirm in Telegram: Telegram posts this callback to our webhook.
  sql("UPDATE providers SET telegram_chat_id = ? WHERE slug = 'demo'", [PROVIDER_CHAT]);
  const bookingId = sql('SELECT id FROM bookings WHERE ref = ?', [ref]);
  const response = await request.post('/api/webhooks/telegram', {
    headers: { 'X-Telegram-Bot-Api-Secret-Token': WEBHOOK_SECRET ?? '' },
    data: {
      update_id: Date.now(),
      callback_query: {
        id: `cb-${Date.now()}`,
        from: { id: Number(PROVIDER_CHAT) },
        message: { message_id: 1, chat: { id: Number(PROVIDER_CHAT), type: 'private' } },
        data: `c:v:${bookingId}`,
      },
    },
  });
  expect(response.status()).toBe(200);

  await page.reload();
  await expect(page.getByText('You’re booked')).toBeVisible();
  await expect(page.getByText('Confirmed', { exact: true })).toBeVisible();
});

test('two people booking the same slot at the same moment: exactly one gets it', async ({ request }) => {
  const slots = await (await request.get('/api/providers/demo/services/code-review/slots')).json();
  const start: string = slots.data.slots.at(-1).start;
  const book = (n: number) =>
    request.post('/api/bookings', {
      data: {
        provider: 'demo',
        service: 'code-review',
        start,
        payment_method: 'upi',
        customer: { name: `Racer ${n}`, email: `race-${n}-${Date.now()}@example.test`, phone: '+910000000000' },
        answers: { repo_link: 'https://example.test/repo', task: 'Review', no_patient_data: true },
      },
    });

  const statuses = (await Promise.all([book(1), book(2)])).map((r) => r.status()).sort();

  expect(statuses).toEqual([201, 409]);
});
