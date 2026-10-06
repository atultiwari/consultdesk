import { Link, useParams } from 'react-router';
import { useProvider } from '../api/hooks';
import type { Service } from '../api/types';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { Avatar } from './ProviderCard';
import './booking.css';

function ServiceCard({
  providerSlug,
  service,
  index,
}: {
  providerSlug: string;
  service: Service;
  index: number;
}) {
  return (
    <li className="service-item rise-in" style={{ animationDelay: `${Math.min(index, 6) * 40}ms` }}>
      <Link to={`/p/${providerSlug}/${service.slug}`} className="card service-card">
        <span className="service-card__head">
          <span className="service-card__title">{service.title}</span>
          <span className="service-card__price">{service.price_display}</span>
        </span>
        {service.tagline && <span className="service-card__tagline">{service.tagline}</span>}
        <span className="service-card__meta">
          <Badge>{service.duration_minutes} min</Badge>
          {service.audience && <Badge tone="brand">For {service.audience}</Badge>}
          {service.requires_approval && <Badge tone="accent">Needs approval</Badge>}
        </span>
        <span className="service-card__cta" aria-hidden="true">
          Choose a time →
        </span>
      </Link>
    </li>
  );
}

export default function ProviderPage() {
  const { provider: slug = '' } = useParams();
  const query = useProvider(slug);

  if (query.isPending) {
    return (
      <div className="container">
        <Loading />
      </div>
    );
  }
  if (query.isError) {
    return (
      <div className="container">
        <Notice tone="danger" title="This page isn't available" live>
          {query.error.message} <Link to="/">See everyone taking bookings</Link>.
        </Notice>
      </div>
    );
  }

  const { provider, services } = query.data;

  return (
    <div className="container provider-page">
      <header className="provider-hero">
        <Avatar provider={provider} size={88} />
        <div className="provider-hero__text">
          <p className="eyebrow">Book a consultation with</p>
          <h1 className="provider-hero__name">{provider.name}</h1>
          {provider.title && <p className="provider-hero__title">{provider.title}</p>}
          {provider.bio && <p className="provider-hero__bio">{provider.bio}</p>}
        </div>
      </header>

      <section aria-labelledby="services-heading" className="services">
        <h2 id="services-heading" className="section-title">
          Choose a session
        </h2>
        {services.length === 0 ? (
          <Notice title="No sessions are open for booking">Please check back soon.</Notice>
        ) : (
          <ul className="service-list" role="list">
            {services.map((service, index) => (
              <ServiceCard
                key={service.slug}
                providerSlug={provider.slug}
                service={service}
                index={index}
              />
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
