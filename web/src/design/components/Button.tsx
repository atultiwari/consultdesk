import type { AnchorHTMLAttributes, ButtonHTMLAttributes, ReactNode } from 'react';
import { Link } from 'react-router';

type Variant = 'primary' | 'secondary' | 'ghost';
type Common = { variant?: Variant; block?: boolean; large?: boolean; children: ReactNode };

function classes(
  { variant = 'primary', block, large }: Omit<Common, 'children'>,
  extra?: string,
): string {
  return [
    'btn',
    variant !== 'primary' && `btn--${variant}`,
    block && 'btn--block',
    large && 'btn--large',
    extra,
  ]
    .filter(Boolean)
    .join(' ');
}

export function Button({
  variant,
  block,
  large,
  className,
  ...rest
}: Common & ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button type="button" className={classes({ variant, block, large }, className)} {...rest} />
  );
}

/** In-app navigation styled as a button. */
export function ButtonLink({ to, variant, block, large, children }: Common & { to: string }) {
  return (
    <Link to={to} className={classes({ variant, block, large })}>
      {children}
    </Link>
  );
}

/** External or non-HTTP links (upi://, https://wa.me) styled as a button. */
export function ButtonAnchor({
  variant,
  block,
  large,
  className,
  ...rest
}: Common & AnchorHTMLAttributes<HTMLAnchorElement>) {
  return <a className={classes({ variant, block, large }, className)} {...rest} />;
}
