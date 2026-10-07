import { useId, type ReactNode } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { Loading, Notice } from '../design/components/Notice';
import { ConnectionsPanel } from './ConnectionsPanel';
import { isStaff, useAdmin } from './context';
import { HoursEditor } from './HoursEditor';
import { useAdminProviders } from './hooks';
import { ProfileForm } from './ProfileForm';
import { RulesForm } from './RulesForm';
import { ServicesPanel } from './ServicesPanel';
import type { AdminProvider } from './types';

const TABS = [
  { key: 'profile', label: 'Profile' },
  { key: 'rules', label: 'Booking rules' },
  { key: 'sessions', label: 'Sessions' },
  { key: 'hours', label: 'Weekly hours' },
  { key: 'connections', label: 'Connections' },
] as const;
type Tab = (typeof TABS)[number]['key'];

function panel(tab: Tab, provider: AdminProvider): ReactNode {
  switch (tab) {
    case 'rules':
      return <RulesForm provider={provider} />;
    case 'sessions':
      return <ServicesPanel providerId={provider.id} />;
    case 'hours':
      return <HoursEditor providerId={provider.id} timezone={provider.timezone} />;
    case 'connections':
      return <ConnectionsPanel providerId={provider.id} />;
    default:
      return <ProfileForm key={provider.id} provider={provider} />;
  }
}

export function ProviderPage() {
  const { base, user } = useAdmin();
  const id = Number(useParams().id);
  const providers = useAdminProviders();
  const [params, setParams] = useSearchParams();
  const tab = (TABS.find((t) => t.key === params.get('tab'))?.key ?? 'profile') as Tab;
  const ids = useId();

  if (providers.isPending) return <Loading />;
  const provider = providers.data?.find((p) => p.id === id);
  if (!provider) {
    return (
      <div className="page">
        <Notice tone="warn" title="We couldn't find that provider">
          {providers.error?.message ?? <Link to={`${base}/providers`}>All providers</Link>}
        </Notice>
      </div>
    );
  }

  return (
    <div className="page">
      {isStaff(user) && (
        <Link className="back" to={`${base}/providers`}>
          ← All providers
        </Link>
      )}
      <header className="page__head">
        <h1>{provider.name}</h1>
        <p className="page__sub">
          Booking page: <span className="mono">/p/{provider.slug}</span> · {provider.timezone}
        </p>
      </header>
      <div className="tabs" role="tablist" aria-label="Provider settings">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            role="tab"
            id={`${ids}-${t.key}`}
            aria-selected={tab === t.key}
            aria-controls={`${ids}-panel`}
            className="tabs__tab"
            onClick={() => {
              const next = new URLSearchParams(params);
              next.set('tab', t.key);
              setParams(next, { replace: true });
            }}
          >
            {t.label}
          </button>
        ))}
      </div>
      <div
        role="tabpanel"
        id={`${ids}-panel`}
        aria-labelledby={`${ids}-${tab}`}
        className="tabs__panel"
      >
        {panel(tab, provider)}
      </div>
    </div>
  );
}
