import { expect, test } from '@playwright/test';

test('home page shows the booking site and its sessions', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Choose a session' })).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('data-preset', /neutral|he|vrl/);
});

test('every page fits the viewport without sideways scrolling', async ({ page }) => {
  for (const path of ['/p/demo', '/p/demo/code-review']) {
    await page.goto(path);
    await page.getByRole('heading', { level: 1 }).waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow, path).toBeLessThanOrEqual(0);
  }
});
