import { useEffect } from 'react';
import { useSite } from '../api/hooks';
import type { Site } from '../api/types';
import { loadPresetFonts } from '../design/fonts';
import { cachePreset, readCachedPreset } from './theme';

const FALLBACK_SITE: Site = {
  org_name: 'Book a session',
  preset: 'neutral',
  accent: null,
  accent_2: null,
  logo_url: null,
  single_provider: null,
};

/**
 * Loads the site settings and applies the preset and fonts. Until they arrive, the last preset
 * seen is used so the page does not flash a different look.
 *
 * @param title the document title, or null to use the organisation's name
 */
export function useSiteTheme(title: string | null = null): Site {
  const { data } = useSite();
  const site = data ?? { ...FALLBACK_SITE, preset: readCachedPreset() ?? FALLBACK_SITE.preset };
  const fromApi = data !== undefined;

  useEffect(() => {
    document.documentElement.setAttribute('data-preset', site.preset);
    void loadPresetFonts(site.preset);
  }, [site.preset]);

  useEffect(() => {
    if (fromApi) cachePreset(site.preset);
  }, [fromApi, site.preset]);

  useEffect(() => {
    document.title = title ?? site.org_name;
  }, [title, site.org_name]);

  return site;
}
