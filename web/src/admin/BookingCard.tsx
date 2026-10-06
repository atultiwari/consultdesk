import { Link } from 'react-router';
import { Badge } from '../design/components/Notice';
import { visitorTimezone } from '../lib/time';
import { BookingButtons } from './BookingButtons';
import { useAdmin } from './context';
import { formatMoney, formatWhen, STATUS_TONE, statusLabel } from './format';
import type { BookingAction, BookingRow } from './types';

export function BookingCard({
  booking,
  actions = [],
}: {
  booking: BookingRow;
  actions?: BookingAction[];
}) {
  const { base } = useAdmin();
  const tz = visitorTimezone();

  return (
    <li className="bcard">
      <div className="bcard__top">
        <Link className="bcard__ref" to={`${base}/bookings/${booking.id}`}>
          {booking.ref}
        </Link>
        <Badge tone={STATUS_TONE[booking.status]}>{statusLabel(booking)}</Badge>
      </div>
      <p className="bcard__who">{booking.customer_name}</p>
      <p className="bcard__what">
        {booking.service_title} · {booking.provider.name}
      </p>
      <p className="bcard__when">
        <time dateTime={booking.start}>{formatWhen(booking.start, tz)}</time>
      </p>
      {booking.amount_minor > 0 && (
        <p className="bcard__money">
          <span>{formatMoney(booking.amount_minor, booking.currency)}</span>
          {booking.utr && (
            <>
              <span className="bcard__utr-label">UTR</span>
              <span className="bcard__utr">{booking.utr}</span>
            </>
          )}
        </p>
      )}
      <BookingButtons bookingId={booking.id} actions={actions} refForLabels={booking.ref} compact />
    </li>
  );
}
