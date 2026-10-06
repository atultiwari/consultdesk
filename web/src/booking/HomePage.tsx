import { Navigate } from 'react-router';
import { useProviders, useSite } from '../api/hooks';
import { Loading, Notice } from '../design/components/Notice';
import { ProviderCard } from './ProviderCard';
import './booking.css';

export default function HomePage() {
  const site = useSite();
  const providers = useProviders();

  if (site.data?.single_provider) {
    return <Navigate to={`/p/${site.data.single_provider}`} replace />;
  }
  if (providers.isPending) {
    return (
      <div className="container">
        <Loading />
      </div>
    );
  }
  if (providers.isError) {
    return (
      <div className="container">
        <Notice tone="danger" title="We couldn't load the list" live>
          {providers.error.message}
        </Notice>
      </div>
    );
  }

  return (
    <div className="container">
      <header className="page-intro">
        <p className="eyebrow">Book a consultation</p>
        <h1 className="page-intro__title">Who would you like to see?</h1>
      </header>
      {providers.data.length === 0 ? (
        <Notice title="No one is taking bookings right now">Please check back soon.</Notice>
      ) : (
        <ul className="provider-grid" role="list">
          {providers.data.map((provider) => (
            <li key={provider.slug}>
              <ProviderCard provider={provider} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
