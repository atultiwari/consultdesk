import type { ProviderProfile, Service } from '../api/types';
import type { Slot } from '../lib/time';
import { formatLongDateTime, formatTime } from '../lib/time';

type Props = {
  provider: ProviderProfile;
  service: Service;
  slot: Slot | null;
  timezone: string;
  /** The price after a coupon, when one is applied. */
  price?: string;
};

function Rows({ provider, service, slot, timezone, price }: Props) {
  return (
    <dl className="summary__rows">
      <div>
        <dt>With</dt>
        <dd>{provider.name}</dd>
      </div>
      <div>
        <dt>When</dt>
        <dd>
          {slot
            ? `${formatLongDateTime(slot.start, timezone)} – ${formatTime(slot.end, timezone)}`
            : 'Choose a time'}
        </dd>
      </div>
      <div>
        <dt>Length</dt>
        <dd>{service.duration_minutes} minutes</dd>
      </div>
      <div>
        <dt>Price</dt>
        <dd>
          {price && price !== service.price_display ? (
            <>
              <s className="summary__was">{service.price_display}</s> {price}
            </>
          ) : (
            service.price_display
          )}
        </dd>
      </div>
    </dl>
  );
}

/** Sticky panel on wide screens; a collapsible bottom sheet on phones. */
export function BookingSummary(props: Props) {
  return (
    <>
      <aside className="summary card" aria-label="Your booking">
        <p className="eyebrow">Your booking</p>
        <p className="summary__title">{props.service.title}</p>
        <Rows {...props} />
      </aside>
      <details className="summary-sheet">
        <summary>
          <span className="summary-sheet__title">{props.service.title}</span>
          <span className="summary-sheet__meta">
            {props.slot
              ? formatLongDateTime(props.slot.start, props.timezone)
              : `${props.service.duration_minutes} min`}{' '}
            · {props.price ?? props.service.price_display}
          </span>
        </summary>
        <Rows {...props} />
      </details>
    </>
  );
}
