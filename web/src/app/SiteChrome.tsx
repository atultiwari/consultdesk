import { useEffect, type ReactNode } from 'react';
import { Link, useLocation } from 'react-router';
import { safeImageUrl } from '../lib/safeUrl';
import { connectToHost, isEmbedded } from './embed';
import { overrideCss } from './theme';
import { ThemeToggle } from './ThemeToggle';
import { useSiteTheme } from './useSiteTheme';
import './chrome.css';

export function SiteChrome({ children }: { children: ReactNode }) {
  const site = useSiteTheme();
  const embedded = isEmbedded();
  const location = useLocation();

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
            <div className="site-header__tools">
              <Link to="/my-bookings" className="site-header__link">
                My bookings
              </Link>
              <ThemeToggle />
            </div>
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
