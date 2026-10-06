// Design QA: captures every public page at 375 / 768 / 1280 px in light and dark.
// Needs the dev stack (docker compose + seed-dev) and the Vite dev server on :5173.
// Usage: npx tsx scripts/screenshots.ts   → e2e/screenshots/*.png
import { chromium, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:5173';
const OUT = new URL('../screenshots/', import.meta.url).pathname;
const VIEWPORTS = [
  { name: '375', width: 375, height: 812, mobile: true },
  { name: '768', width: 768, height: 1024, mobile: false },
  { name: '1280', width: 1280, height: 800, mobile: false },
];
const THEMES = ['light', 'dark'] as const;
const SERVICE = '/p/demo/research-guidance';

async function chooseSlot(page: Page) {
  await page.goto(BASE + SERVICE);
  await page.locator('.slot').first().click();
}

async function fillDetails(page: Page, email: string) {
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('Full name').fill('Asha Placeholder');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('WhatsApp number').fill('+91 00000 00000');
  await page.getByLabel('Your role').selectOption('Resident');
  await page.getByLabel('What do you want to walk away with?').fill('Feedback on my study design.');
  await page.getByLabel('Stage').selectOption('Protocol');
  await page.getByLabel(/identifiable patient data/).check();
}

async function shot(page: Page, name: string) {
  await page.waitForTimeout(450); // let entrance motion settle
  await page.screenshot({ path: `${OUT}${name}.png`, fullPage: true });
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  let statusUrl = '';

  for (const theme of THEMES) {
    for (const vp of VIEWPORTS) {
      const context = await browser.newContext({
        viewport: { width: vp.width, height: vp.height },
        isMobile: vp.mobile,
        hasTouch: vp.mobile,
        colorScheme: theme,
        reducedMotion: 'no-preference',
      });
      const page = await context.newPage();
      const tag = `${vp.name}-${theme}`;

      await page.goto(BASE + '/p/demo');
      await page.getByRole('heading', { level: 1 }).waitFor();
      await shot(page, `1-provider-${tag}`);

      await chooseSlot(page);
      await shot(page, `2-time-${tag}`);

      await fillDetails(page, `shots-${Date.now()}@example.test`);
      await shot(page, `3-details-${tag}`);

      await page.getByRole('button', { name: 'Continue' }).click();
      await page.getByRole('heading', { name: 'Review & pay' }).waitFor();
      await shot(page, `4-review-${tag}`);

      if (statusUrl === '') {
        await page.getByRole('button', { name: /Book and pay/ }).click();
        await page.waitForURL(/\/b\//);
        statusUrl = page.url();
      }
      await page.goto(statusUrl);
      await page.getByText('Appointment slip').waitFor();
      await shot(page, `5-status-${tag}`);

      await context.close();
    }
  }
  await browser.close();
  console.log(`Screenshots in ${OUT} (status page: ${statusUrl})`);
}

main().catch((error: unknown) => {
  console.error(error);
  process.exit(1);
});
