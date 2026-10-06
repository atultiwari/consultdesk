import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5173';
const isCI = Boolean(process.env.CI);

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  forbidOnly: isCI,
  retries: isCI ? 1 : 0,
  reporter: isCI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'mobile-375', testMatch: /smoke/, use: { ...devices['Pixel 7'], viewport: { width: 375, height: 812 } } },
    { name: 'tablet-768', testMatch: /smoke/, use: { ...devices['Desktop Chrome'], viewport: { width: 768, height: 1024 } } },
    { name: 'desktop-1280', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
  ],
  // The Vite dev server proxies /api to the PHP API (API_DEV_ORIGIN, default http://localhost:8080).
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : { command: 'npm --prefix ../web run dev -- --port 5173 --strictPort', url: BASE_URL, reuseExistingServer: !isCI },
});
