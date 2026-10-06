import { Link, useParams } from 'react-router';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { safeHttpsUrl } from '../lib/safeUrl';
import { visitorTimezone } from '../lib/time';
import { BookingButtons } from './BookingButtons';
import { useAdmin } from './context';
import { formatMoney, formatWhen, historyLabel, STATUS_TONE, statusLabel } from './format';
import { useBookingDetail } from './hooks';
import type { BookingDetail } from './types';

function answerText(value: unknown): string {
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  return typeof value === 'string' ? value : JSON.stringify(value);
}

function Facts({ booking }: { booking: BookingDetail }) {
  const tz = visitorTimezone();
  const meet = safeHttpsUrl(booking.meet_url);
  return (
    <dl className="facts">
      <div>
        <dt>When</dt>
        <dd>
          <time dateTime={booking.start}>{formatWhen(booking.start, tz)}</time>
          {booking.customer.timezone && booking.customer.timezone !== tz && (
            <span className="cell-sub">
              {formatWhen(booking.start, booking.customer.timezone)} for the customer
            </span>
          )}
        </dd>
      </div>
      <div>
        <dt>Session</dt>
        <dd>
          {booking.service_title}
          <span className="cell-sub">with {booking.provider.name}</span>
        </dd>
      </div>
      <div>
        <dt>Payment</dt>
        <dd>
          {booking.amount_minor > 0 ? formatMoney(booking.amount_minor, booking.currency) : 'Free'}
          {booking.payment_method === 'upi' && <span className="cell-sub">by UPI</span>}
          {booking.utr && (
            <span className="cell-sub">
              UTR <span className="mono">{booking.utr}</span>
            </span>
          )}
        </dd>
      </div>
      {meet && (
        <div>
          <dt>Video call</dt>
          <dd>
            <a href={meet} target="_blank" rel="noreferrer">
              Join the call
            </a>
          </dd>
        </div>
      )}
    </dl>
  );
}

export function BookingDetailPage() {
  const { base } = useAdmin();
  const id = Number(useParams().id);
  const booking = useBookingDetail(id);
  const tz = visitorTimezone();

  if (booking.isPending) return <Loading />;
  if (booking.isError) {
    return (
      <div className="page">
        <Notice tone="warn" title="We couldn't find that booking">
          <p>It may belong to another provider, or the link is wrong.</p>
          <Link to={`${base}/bookings`}>All bookings</Link>
        </Notice>
      </div>
    );
  }
  const b = booking.data;

  return (
    <div className="page page--detail">
      <Link className="back" to={`${base}/bookings`}>
        ← All bookings
      </Link>
      <header className="page__head page__head--row">
        <h1>
          Booking <span className="mono">{b.ref}</span>
        </h1>
        <Badge tone={STATUS_TONE[b.status]}>{statusLabel(b)}</Badge>
      </header>

      <BookingButtons bookingId={b.id} actions={b.actions} />

      <div className="detail-grid">
        <section className="panel" aria-label="Booking">
          <Facts booking={b} />
        </section>
        <section className="panel" aria-labelledby="customer-h">
          <h2 id="customer-h">Customer</h2>
          <dl className="facts">
            <div>
              <dt>Name</dt>
              <dd>{b.customer.name}</dd>
            </div>
            <div>
              <dt>Email</dt>
              <dd>
                <a href={`mailto:${b.customer.email}`}>{b.customer.email}</a>
              </dd>
            </div>
            {b.customer.phone && (
              <div>
                <dt>Phone</dt>
                <dd>{b.customer.phone}</dd>
              </div>
            )}
          </dl>
        </section>
        {b.answers.length > 0 && (
          <section className="panel" aria-labelledby="answers-h">
            <h2 id="answers-h">Answers</h2>
            <dl className="facts facts--stacked">
              {b.answers.map((answer) => (
                <div key={answer.id}>
                  <dt>{answer.label}</dt>
                  <dd className="prewrap">{answerText(answer.value)}</dd>
                </div>
              ))}
            </dl>
          </section>
        )}
        <section className="panel" aria-labelledby="history-h">
          <h2 id="history-h">History</h2>
          <ol className="timeline" aria-label="History">
            {b.history.map((entry, index) => (
              <li key={index}>
                <span className="timeline__what">
                  {historyLabel(entry.action)}
                  {entry.actor && <span className="cell-sub"> by {entry.actor}</span>}
                  {entry.actor_type === 'telegram' && (
                    <span className="cell-sub"> via Telegram</span>
                  )}
                </span>
                <time dateTime={entry.at}>{formatWhen(entry.at, tz)}</time>
              </li>
            ))}
          </ol>
        </section>
      </div>
    </div>
  );
}
