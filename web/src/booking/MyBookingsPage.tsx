import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { ApiError } from '../api/client';
import {
  useCancelMine,
  useMyBookings,
  useRequestLink,
  useSignInWithLink,
  useSignOutMine,
} from '../api/myHooks';
import type { MyBooking } from '../api/types';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { formatLongDateTime } from '../lib/time';
import { STATUS } from './statusLabels';
import './my-bookings.css';

/** The status page's path and query, so the link stays inside this app. */
function localPath(url: string | null): string | null {
  if (!url) return null;
  try {
    const parsed = new URL(url);
    return `${parsed.pathname}${parsed.search}`;
  } catch {
    return null;
  }
}

/**
 * "My bookings": customers sign in with a link emailed to them (no password), then see what they've
 * booked with that address.
 */
export default function MyBookingsPage() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const signIn = useSignInWithLink();
  const bookings = useMyBookings();
  const started = useRef(false);
  const token = params.get('token');

  useEffect(() => {
    if (!token || started.current) return;
    started.current = true;
    // The token leaves the address bar (and browser history) straight away.
    void navigate('/my-bookings', { replace: true });
    signIn.mutate(token);
  }, [token, navigate, signIn]);

  const signedOut = bookings.error instanceof ApiError && bookings.error.status === 401;

  return (
    <div className="container my-bookings">
      <h1 className="my-bookings__title">Your bookings</h1>
      {/* Also while the list reloads after signing in, so the sign-in form doesn't flash up. */}
      {signIn.isPending || (!bookings.data && bookings.isFetching) ? (
        <Loading />
      ) : bookings.data ? (
        <BookingLists data={bookings.data} />
      ) : signedOut ? (
        <>
          {signIn.isError && (
            <Notice tone="danger" live>
              {signIn.error.message}
            </Notice>
          )}
          <LinkForm />
        </>
      ) : (
        <Notice tone="danger" live>
          {bookings.error?.message ?? 'Something went wrong.'}
        </Notice>
      )}
    </div>
  );
}

function LinkForm() {
  const request = useRequestLink();
  const [email, setEmail] = useState('');

  if (request.isSuccess) {
    return (
      <Notice tone="success" title="Check your email" live>
        If there are bookings for {request.variables}, we’ve sent a link to see them. It works once,
        for 15 minutes.
      </Notice>
    );
  }

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (email.trim() !== '') request.mutate(email.trim());
  };

  return (
    <form className="card my-bookings__form" onSubmit={submit} noValidate>
      <p className="muted">
        Enter the email you booked with and we’ll send you a link. No password needed.
      </p>
      <Field label="Email">
        <input
          className="input"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
      </Field>
      {request.isError && (
        <Notice tone="danger" live>
          {request.error.message}
        </Notice>
      )}
      <Button type="submit" disabled={request.isPending}>
        Email me a link
      </Button>
    </form>
  );
}

function BookingLists({
  data,
}: {
  data: { email: string; upcoming: MyBooking[]; past: MyBooking[] };
}) {
  const signOut = useSignOutMine();
  return (
    <>
      <p className="my-bookings__who muted">
        Signed in as {data.email}.{' '}
        <button type="button" className="link-button" onClick={() => signOut.mutate()}>
          Sign out
        </button>
      </p>
      <section aria-labelledby="upcoming-h">
        <h2 id="upcoming-h" className="my-bookings__section">
          Upcoming
        </h2>
        {data.upcoming.length === 0 ? (
          <p className="muted">
            No upcoming bookings. <Link to="/">Book a session</Link>
          </p>
        ) : (
          <ul className="my-bookings__list">
            {data.upcoming.map((b) => (
              <BookingCard key={b.ref} booking={b} />
            ))}
          </ul>
        )}
      </section>
      {data.past.length > 0 && (
        <section aria-labelledby="past-h">
          <h2 id="past-h" className="my-bookings__section">
            Past and cancelled
          </h2>
          <ul className="my-bookings__list">
            {data.past.map((b) => (
              <BookingCard key={b.ref} booking={b} />
            ))}
          </ul>
        </section>
      )}
    </>
  );
}

function BookingCard({ booking }: { booking: MyBooking }) {
  const cancel = useCancelMine();
  const [asking, setAsking] = useState(false);
  const status = STATUS[booking.status];
  const href = localPath(booking.status_url);

  return (
    <li className="card my-booking">
      <div className="my-booking__main">
        <p className="my-booking__when">{formatLongDateTime(booking.start, booking.timezone)}</p>
        <p className="my-booking__what">
          {booking.service.title} <span className="muted">with {booking.provider.name}</span>
        </p>
        <p className="my-booking__meta">
          <Badge tone={status.tone}>{status.label}</Badge>{' '}
          <span className="mono">{booking.ref}</span> · {booking.amount_display}
        </p>
      </div>
      <div className="my-booking__actions">
        {href && (
          <Link className="btn btn--secondary" to={href}>
            Open <span className="visually-hidden">{booking.ref}</span>
          </Link>
        )}
        {booking.can_cancel &&
          (asking ? (
            <>
              <Button
                className="btn--danger"
                disabled={cancel.isPending}
                onClick={() => cancel.mutate(booking.ref)}
              >
                Yes, cancel it
              </Button>
              <Button variant="ghost" autoFocus onClick={() => setAsking(false)}>
                Keep it
              </Button>
            </>
          ) : (
            <Button variant="ghost" onClick={() => setAsking(true)}>
              Cancel booking
            </Button>
          ))}
      </div>
      {cancel.isError && (
        <Notice tone="danger" live>
          {cancel.error.message}
        </Notice>
      )}
    </li>
  );
}
