import type { Preset } from '../api/types';

/**
 * Self-hosted fonts, bundled by Vite. Each preset's fonts are a separate chunk, so a site only
 * downloads the fonts its preset uses (neutral uses system fonts and downloads nothing).
 */
const loaders: Record<Preset, () => Promise<unknown>> = {
  neutral: () => Promise.resolve(),
  he: () =>
    Promise.all([
      import('@fontsource/instrument-serif/400.css'),
      import('@fontsource/instrument-serif/400-italic.css'),
      import('@fontsource/geist-sans/400.css'),
      import('@fontsource/geist-sans/500.css'),
      import('@fontsource/geist-sans/600.css'),
      import('@fontsource/geist-mono/400.css'),
      import('@fontsource/geist-mono/500.css'),
    ]),
  vrl: () =>
    Promise.all([
      import('@fontsource/bricolage-grotesque/700.css'),
      import('@fontsource/bricolage-grotesque/800.css'),
      import('@fontsource/figtree/400.css'),
      import('@fontsource/figtree/500.css'),
      import('@fontsource/figtree/600.css'),
      import('@fontsource/figtree/700.css'),
    ]),
};

export function loadPresetFonts(preset: Preset): Promise<unknown> {
  return loaders[preset]().catch(() => undefined); // fall back to system fonts
}
