import { Link, useParams, useSearchParams } from 'react-router';
import { useBooking } from '../api/hooks';
import type { BookingView } from '../api/types';
import { ButtonAnchor, ButtonLink } from '../design/components/Button';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { safeHttpsUrl } from '../lib/safeUrl';
import { formatLongDateTime, formatTime, timezoneLabel } from '../lib/time';
import { PaymentPanel } from './PaymentPanel';
import { RazorpayReturn } from './RazorpayReturn';
import { STATUS } from './statusLabels';
import './booking.css';
import './status.css';

function Outcome({ booking }: { booking: BookingView }) {
  switch (booking.status) {
    case 'confirmed':
      return (
        <Notice tone="success" title="You’re booked" live>
          A calendar invite and confirmation are on their way to your email.
          {safeHttpsUrl(booking.meet_url) && (
            <div className="outcome__action">
              <ButtonAnchor
                href={safeHttpsUrl(booking.meet_url) ?? undefined}
                target="_blank"
                rel="noopener noreferrer"
              >
                Join the video call
              </ButtonAnchor>
            </div>
          )}
        </Notice>
      );
    case 'held':
      return booking.payment === null ? (
        <Notice tone="info" title="Request sent" live>
          {booking.provider.name} will review it and email you once it’s approved.
        </Notice>
      ) : null;
    case 'expired':
      return (
        <Notice tone="danger" title="This hold has expired" live>
          {booking.utr ? (
            <>
              Your payment (UTR {booking.utr}) couldn’t be verified in time. Please contact{' '}
              {booking.provider.name} with your booking reference.
            </>
          ) : (
            <>The time was released. Please don’t pay for this booking.</>
          )}
          <div className="outcome__action">
            <ButtonLink to={`/p/${booking.provider.slug}`} variant="secondary">
              Book a new time
            </ButtonLink>
          </div>
        </Notice>
      );
    case 'rejected':
    case 'cancelled':
      return (
        <Notice
          tone="danger"
          title={
            booking.status === 'rejected'
              ? 'This booking wasn’t confirmed'
              : 'This booking was cancelled'
          }
          live
        >
          If you’ve already paid, please contact {booking.provider.name} with your booking
          reference.
        </Notice>
      );
    default:
      return null;
  }
}

function Slip({ booking }: { booking: BookingView }) {
  const status = STATUS[booking.status];
  return (
    <article className="slip" aria-labelledby="slip-title">
      <header className="slip__head">
        <p className="eyebrow">Appointment slip</p>
        <Badge tone={status.tone}>{status.label}</Badge>
      </header>
      <p
        className="slip__ref mono"
        aria-label={`Booking reference ${booking.ref.split('').join(' ')}`}
      >
        {booking.ref}
      </p>
      <h1 id="slip-title" className="slip__title">
        {booking.service.title}
      </h1>
      <dl className="slip__rows">
        <div>
          <dt>With</dt>
          <dd>{booking.provider.name}</dd>
        </div>
        <div>
          <dt>When</dt>
          <dd>
            {formatLongDateTime(booking.start, booking.timezone)} –{' '}
            {formatTime(booking.end, booking.timezone)}
            <span className="slip__tz">
              {timezoneLabel(booking.timezone, new Date(booking.start))}
            </span>
          </dd>
        </div>
        <div>
          <dt>For</dt>
          <dd>{booking.customer_name}</dd>
        </div>
        <div>
          <dt>Fee</dt>
          <dd className="mono">{booking.amount_display}</dd>
        </div>
        {booking.coupon_code && booking.discount_display && (
          <div>
            <dt>Coupon</dt>
            <dd>
              <span className="mono">{booking.coupon_code}</span> saved you{' '}
              {booking.discount_display}
            </dd>
          </div>
        )}
      </dl>
      <div className="slip__perforation" aria-hidden="true" />
      <p className="slip__foot muted">Keep this page: its link is in your email too.</p>
    </article>
  );
}

export default function StatusPage() {
  const { ref = '' } = useParams();
  const [params] = useSearchParams();
  const token = params.get('t') ?? '';
  const query = useBooking(ref, token);

  if (params.get('paid') === '1' && params.has('razorpay_signature')) {
    return <RazorpayReturn refCode={ref} params={params} />;
  }

  // A failed background refresh keeps the last good data; only a failed first load is an error.
  if (token === '' || (query.isError && !query.data)) {
    return (
      <div className="container status">
        <Notice tone="danger" title="We couldn’t find that booking" live>
          Open the link from your booking email, exactly as sent. <Link to="/">Book a session</Link>
        </Notice>
      </div>
    );
  }
  if (!query.data) {
    return (
      <div className="container">
        <Loading label="Loading your booking…" />
      </div>
    );
  }

  const booking = query.data;
  return (
    <div className="container status">
      <div className="status__grid">
        <Slip booking={booking} />
        <div className="status__side">
          <Outcome booking={booking} />
          <PaymentPanel booking={booking} token={token} />
        </div>
      </div>
    </div>
  );
}
