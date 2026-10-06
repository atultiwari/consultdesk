import type { ReactNode } from 'react';

type Tone = 'info' | 'warn' | 'danger' | 'success' | 'neutral';

export function Notice({
  tone = 'neutral',
  title,
  children,
  live,
}: {
  tone?: Tone;
  title?: ReactNode;
  children?: ReactNode;
  live?: boolean;
}) {
  return (
    <div
      className={`notice notice--${tone}`}
      role={live ? (tone === 'danger' ? 'alert' : 'status') : undefined}
    >
      {title && <p className="notice__title">{title}</p>}
      {children && <div>{children}</div>}
    </div>
  );
}

export function Badge({
  tone,
  children,
}: {
  tone?: 'brand' | 'accent' | 'success' | 'warn' | 'danger';
  children: ReactNode;
}) {
  return <span className={tone ? `badge badge--${tone}` : 'badge'}>{children}</span>;
}

export function Loading({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="loading" role="status">
      <span className="spinner" aria-hidden="true" />
      <span>{label}</span>
    </div>
  );
}
