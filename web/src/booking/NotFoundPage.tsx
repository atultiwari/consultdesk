import { ButtonLink } from '../design/components/Button';

export default function NotFoundPage() {
  return (
    <div className="container" style={{ display: 'grid', gap: '1rem', justifyItems: 'start' }}>
      <p className="eyebrow">404</p>
      <h1>This page doesn't exist</h1>
      <p className="muted">The link may be mistyped or out of date.</p>
      <ButtonLink to="/">Go to bookings</ButtonLink>
    </div>
  );
}
