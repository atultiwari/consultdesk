import { useEffect, useState, type ReactNode } from 'react';
import { Link, useLocation } from 'react-router';
import { useSite } from '../api/hooks';
import type { Site } from '../api/types';
import { loadPresetFonts } from '../design/fonts';
import { safeImageUrl } from '../lib/safeUrl';
import { connectToHost, isEmbedded } from './embed';
import {
  applyTheme,
  cachePreset,
  overrideCss,
  readCachedPreset,
  readThemeChoice,
  saveThemeChoice,
  type ThemeChoice,
} from './theme';
import './chrome.css';

const FALLBACK_SITE: Site = {
  org_name: 'Book a session',
  preset: 'neutral',
  accent: null,
  accent_2: null,
  logo_url: null,
  single_provider: null,
};

function useSiteTheme(site: Site, fromApi: boolean) {
  useEffect(() => {
    document.documentElement.setAttribute('data-preset', site.preset);
    void loadPresetFonts(site.preset);
  }, [site.preset]);

  useEffect(() => {
    if (fromApi) cachePreset(site.preset);
  }, [fromApi, site.preset]);

  useEffect(() => {
    document.title = site.org_name;
  }, [site.org_name]);
}

function ThemeToggle() {
  const [choice, setChoice] = useState<ThemeChoice>(readThemeChoice);

  useEffect(() => {
    applyTheme(choice);
    const media = window.matchMedia?.('(prefers-color-scheme: dark)');
    const onChange = () => applyTheme(choice);
    media?.addEventListener('change', onChange);
    return () => media?.removeEventListener('change', onChange);
  }, [choice]);

  const next: Record<ThemeChoice, ThemeChoice> = { system: 'light', light: 'dark', dark: 'system' };
  const label: Record<ThemeChoice, string> = { system: 'Auto', light: 'Light', dark: 'Dark' };

  return (
    <button
      type="button"
      className="theme-toggle"
      onClick={() => {
        const value = next[choice];
        saveThemeChoice(value);
        setChoice(value);
      }}
      aria-label={`Colour theme: ${label[choice]}. Change theme`}
    >
      <span aria-hidden="true" className={`theme-toggle__icon theme-toggle__icon--${choice}`} />
      {label[choice]}
    </button>
  );
}

export function SiteChrome({ children }: { children: ReactNode }) {
  const { data } = useSite();
  const site = data ?? { ...FALLBACK_SITE, preset: readCachedPreset() ?? FALLBACK_SITE.preset };
  const embedded = isEmbedded();
  const location = useLocation();
  useSiteTheme(site, data !== undefined);

  useEffect(() => (embedded ? connectToHost() : undefined), [embedded]);

  const overrides = overrideCss(site);

  return (
    <>
      {overrides && <style>{overrides}</style>}
      <a className="skip-link" href="#main">
        Skip to content
      </a>
      {!embedded && (
        <header className="site-header">
          <div className="container site-header__inner">
            <Link to="/" className="site-header__brand">
              {safeImageUrl(site.logo_url) ? (
                <img
                  src={safeImageUrl(site.logo_url) ?? undefined}
                  alt=""
                  className="site-header__logo"
                />
              ) : (
                <span className="site-header__mark" aria-hidden="true" />
              )}
              <span>{site.org_name}</span>
            </Link>
            <ThemeToggle />
          </div>
        </header>
      )}
      <main
        id="main"
        className={embedded ? 'site-main site-main--embedded' : 'site-main'}
        key={location.pathname}
      >
        {children}
      </main>
      {!embedded && (
        <footer className="site-footer">
          <div className="container">
            <p>
              Times are shown in your own timezone.{' '}
              <span className="site-footer__by">Bookings by ConsultDesk</span>
            </p>
          </div>
        </footer>
      )}
    </>
  );
}
