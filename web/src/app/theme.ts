import type { Preset, Site } from '../api/types';

export type ThemeChoice = 'system' | 'light' | 'dark';
const STORAGE_KEY = 'consultdesk-theme';
const PRESET_KEY = 'consultdesk-preset';
const PRESETS: Preset[] = ['neutral', 'he', 'vrl'];

/** The preset seen on the last visit, so its look and fonts apply before the API answers. */
export function readCachedPreset(): Preset | null {
  try {
    const stored = window.localStorage.getItem(PRESET_KEY);
    return PRESETS.find((p) => p === stored) ?? null;
  } catch {
    return null;
  }
}

export function cachePreset(preset: Preset): void {
  try {
    window.localStorage.setItem(PRESET_KEY, preset);
  } catch {
    // Not essential: the next visit simply waits for the API.
  }
}

export function readThemeChoice(): ThemeChoice {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);
    return stored === 'light' || stored === 'dark' ? stored : 'system';
  } catch {
    return 'system';
  }
}

export function saveThemeChoice(choice: ThemeChoice): void {
  try {
    if (choice === 'system') window.localStorage.removeItem(STORAGE_KEY);
    else window.localStorage.setItem(STORAGE_KEY, choice);
  } catch {
    // Storage blocked (private mode): the choice lasts for this page only.
  }
}

/** Sets data-theme (explicit choice) and data-mode (what is actually shown) on <html>. */
export function applyTheme(choice: ThemeChoice): void {
  const root = document.documentElement;
  if (choice === 'system') root.removeAttribute('data-theme');
  else root.setAttribute('data-theme', choice);

  const dark =
    choice === 'dark' ||
    (choice === 'system' && window.matchMedia?.('(prefers-color-scheme: dark)').matches);
  root.setAttribute('data-mode', dark ? 'dark' : 'light');
}

/** Readable text colour (near-black or white) on a given background. */
export function inkOn(hex: string): string {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
  const lin = (c: number) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
  const luminance = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  return luminance > 0.4 ? '#111111' : '#ffffff';
}

/**
 * Brand colours chosen in the admin panel override the preset in the light theme only; dark themes
 * keep their tuned palette, because an arbitrary colour rarely reads well on a dark background.
 */
const HEX = /^#[0-9a-f]{6}$/i;

export function overrideCss(site: Site): string {
  const rules: string[] = [];
  if (site.accent && HEX.test(site.accent))
    rules.push(`--brand:${site.accent};--brand-ink:${inkOn(site.accent)};`);
  if (site.accent_2 && HEX.test(site.accent_2)) rules.push(`--accent:${site.accent_2};`);
  return rules.length === 0 ? '' : `:root[data-mode='light']{${rules.join('')}}`;
}
