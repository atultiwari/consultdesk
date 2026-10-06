/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

const API_DEV_ORIGIN = process.env.API_DEV_ORIGIN ?? 'http://localhost:8080';

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: { '/api': API_DEV_ORIGIN },
  },
  preview: {
    proxy: { '/api': API_DEV_ORIGIN },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/setup.ts'],
    // Some admin flows click through several screens; leave room on a busy CI runner.
    testTimeout: 15_000,
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/main.tsx', 'src/vite-env.d.ts'],
      thresholds: { lines: 80, functions: 80, branches: 80, statements: 80 },
    },
  },
});
