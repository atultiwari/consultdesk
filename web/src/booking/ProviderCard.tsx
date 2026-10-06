import { Link } from 'react-router';
import type { ProviderProfile } from '../api/types';
import { initials } from '../lib/initials';
import { safeImageUrl } from '../lib/safeUrl';

export function Avatar({ provider, size = 56 }: { provider: ProviderProfile; size?: number }) {
  const photo = safeImageUrl(provider.photo_url);
  return photo ? (
    <img className="avatar" src={photo} alt="" width={size} height={size} />
  ) : (
    <span
      className="avatar avatar--initials"
      style={{ width: size, height: size }}
      aria-hidden="true"
    >
      {initials(provider.name)}
    </span>
  );
}

export function ProviderCard({ provider }: { provider: ProviderProfile }) {
  return (
    <Link to={`/p/${provider.slug}`} className="card provider-card">
      <Avatar provider={provider} />
      <span className="provider-card__text">
        <span className="provider-card__name">{provider.name}</span>
        {provider.title && (
          <span className="provider-card__title">
            <span className="visually-hidden">, </span>
            {provider.title}
          </span>
        )}
      </span>
      <span className="provider-card__arrow" aria-hidden="true">
        →
      </span>
    </Link>
  );
}
