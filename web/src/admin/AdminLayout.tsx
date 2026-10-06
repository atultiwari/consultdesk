import { useEffect, useRef, useState, type ReactNode } from 'react';
import { NavLink, useLocation } from 'react-router';
import { ThemeToggle } from '../app/ThemeToggle';
import { useSite } from '../api/hooks';
import { isStaff, useAdmin } from './context';
import { useLogout } from './hooks';

function AccountMenu() {
  const { user } = useAdmin();
  const logout = useLogout();
  const [open, setOpen] = useState(false);
  const menu = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    menu.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
    const close = (event: MouseEvent | KeyboardEvent) => {
      if (
        event instanceof KeyboardEvent
          ? event.key === 'Escape'
          : !menu.current?.contains(event.target as Node)
      ) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', close);
    document.addEventListener('keydown', close);
    return () => {
      document.removeEventListener('mousedown', close);
      document.removeEventListener('keydown', close);
    };
  }, [open]);

  return (
    <div className="account" ref={menu}>
      <button
        type="button"
        className="account__button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Account: ${user.name ?? user.email}`}
        onClick={() => setOpen((value) => !value)}
      >
        <span className="account__avatar" aria-hidden="true">
          {(user.name ?? user.email).slice(0, 1).toUpperCase()}
        </span>
        <span className="account__who">
          <span className="account__name">{user.name ?? user.email}</span>
          <span className="account__role">{user.role}</span>
        </span>
      </button>
      {open && (
        <div className="account__menu" role="menu">
          <button type="button" role="menuitem" onClick={() => logout.mutate(false)}>
            Sign out
          </button>
          <button type="button" role="menuitem" onClick={() => logout.mutate(true)}>
            Sign out everywhere
          </button>
        </div>
      )}
    </div>
  );
}

export function AdminLayout({ children }: { children: ReactNode }) {
  const { base, user } = useAdmin();
  const { data: site } = useSite();
  const location = useLocation();
  const links = [
    { to: base, label: 'Dashboard', end: true },
    { to: `${base}/bookings`, label: 'Bookings' },
    isStaff(user) || user.provider_id === null
      ? { to: `${base}/providers`, label: 'Providers' }
      : { to: `${base}/providers/${user.provider_id}`, label: 'My profile' },
    { to: `${base}/blocked`, label: 'Blocked times' },
    ...(user.role === 'owner'
      ? [
          { to: `${base}/users`, label: 'Users' },
          { to: `${base}/branding`, label: 'Branding' },
          { to: `${base}/payments`, label: 'Payments' },
          { to: `${base}/system`, label: 'System' },
        ]
      : []),
    { to: `${base}/account`, label: 'My account' },
  ];

  return (
    <div className="admin">
      <a className="skip-link" href="#main">
        Skip to content
      </a>
      <aside className="admin__rail">
        <p className="admin__brand">
          <span className="admin__mark" aria-hidden="true" />
          <span>
            {site?.org_name ?? 'ConsultDesk'}
            <span className="admin__brand-sub">Admin</span>
          </span>
        </p>
        <nav aria-label="Admin" className="admin__nav">
          {links.map((link) => (
            <NavLink key={link.to} to={link.to} end={link.end} className="admin__link">
              {link.label}
            </NavLink>
          ))}
        </nav>
        <div className="admin__foot">
          <ThemeToggle />
          <AccountMenu />
        </div>
      </aside>
      <main id="main" className="admin__main" key={location.pathname}>
        {children}
      </main>
    </div>
  );
}
