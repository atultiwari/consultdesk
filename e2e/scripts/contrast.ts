// WCAG AA check of every text/background token pair the UI uses, for each preset and theme.
// Reads the real computed colours from the running app. Usage: npx tsx scripts/contrast.ts
import { chromium } from '@playwright/test';

const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:5173';
const PAIRS: [fg: string, bg: string, min: number][] = [
  ['--ink', '--bg', 4.5], ['--ink', '--bg-elev', 4.5], ['--ink', '--bg-sunk', 4.5],
  ['--ink-2', '--bg', 4.5], ['--ink-2', '--bg-elev', 4.5], ['--ink-2', '--bg-sunk', 4.5],
  ['--ink-3', '--bg', 4.5], ['--ink-3', '--bg-elev', 4.5], ['--ink-3', '--bg-sunk', 4.5],
  ['--brand', '--bg', 4.5], ['--brand', '--bg-elev', 4.5], ['--brand-ink', '--brand', 4.5],
  ['--brand', '--brand-soft', 4.5], ['--accent', '--bg', 4.5], ['--accent', '--accent-soft', 4.5],
  ['--success', '--success-soft', 4.5], ['--warn', '--warn-soft', 4.5], ['--danger', '--danger-soft', 4.5],
  ['--danger', '--bg-elev', 4.5], ['--ink', '--brand-soft', 4.5], ['--ink', '--warn-soft', 4.5],
  ['--rule-strong', '--bg-elev', 1.5], ['--focus', '--bg', 3],
];

function luminance(rgb: number[]): number {
  const [r, g, b] = rgb.map((c) => {
    const v = c / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(a: number[], b: number[]): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}

async function main() {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto(BASE + '/');
  let failures = 0;

  for (const preset of ['neutral', 'he', 'vrl']) {
    for (const theme of ['light', 'dark']) {
      const colours = await page.evaluate(
        ([p, t, names]) => {
          const root = document.documentElement;
          root.setAttribute('data-preset', p);
          root.setAttribute('data-theme', t);
          const probe = document.createElement('div');
          document.body.append(probe);
          const out: Record<string, number[]> = {};
          for (const name of names) {
            probe.style.color = `var(${name})`;
            out[name] = (getComputedStyle(probe).color.match(/\d+(\.\d+)?/g) ?? []).slice(0, 3).map(Number);
          }
          probe.remove();
          return out;
        },
        [preset, theme, [...new Set(PAIRS.flatMap(([f, b]) => [f, b]))]] as const,
      );

      for (const [fg, bg, min] of PAIRS) {
        const value = ratio(colours[fg], colours[bg]);
        if (value < min) {
          failures++;
          console.log(`FAIL ${preset}/${theme}: ${fg} on ${bg} = ${value.toFixed(2)} (needs ${min})`);
        }
      }
    }
  }
  await browser.close();
  console.log(failures === 0 ? 'All pairs meet WCAG AA.' : `${failures} pair(s) below WCAG AA.`);
  process.exit(failures === 0 ? 0 : 1);
}

void main();
