import { useId, type ReactNode } from 'react';
import { Loading, Notice } from '../design/components/Notice';
import { visitorTimezone } from '../lib/time';
import { BookingCard } from './BookingCard';
import { Link } from 'react-router';
import { useAdmin } from './context';
import { useDashboard } from './hooks';
import { useSetup } from './setupHooks';
import type { BookingAction, BookingRow } from './types';

function Queue({
  title,
  hint,
  rows,
  empty,
  actions,
  tone,
}: {
  title: string;
  hint?: ReactNode;
  rows: BookingRow[];
  empty: string;
  actions?: BookingAction[];
  tone?: 'urgent';
}) {
  const id = useId();
  return (
    <section className={tone ? `queue queue--${tone}` : 'queue'} aria-labelledby={id}>
      <header className="queue__head">
        <h2 id={id}>
          {title}
          <span className="queue__count">{rows.length}</span>
        </h2>
        {hint && <p className="queue__hint">{hint}</p>}
      </header>
      {rows.length === 0 ? (
        <p className="queue__empty">{empty}</p>
      ) : (
        <ul className="queue__list">
          {rows.map((row) => (
            <BookingCard key={row.id} booking={row} actions={actions} />
          ))}
        </ul>
      )}
    </section>
  );
}

/** Until the owner has been through "Set up your site", the dashboard points them there. */
function SetupReminder() {
  const { base, user } = useAdmin();
  const setup = useSetup(user.role === 'owner');
  if (!setup.data || setup.data.completed) return null;
  return (
    <Notice tone="info" title="Finish setting up your site">
      <p>Choose who people book with, add the teacher’s details and pick some starter sessions.</p>
      <Link className="btn" to={`${base}/setup`}>
        Set up your site
      </Link>
    </Notice>
  );
}

export function DashboardPage() {
  const timezone = visitorTimezone();
  const dashboard = useDashboard(timezone);

  return (
    <div className="page">
      <header className="page__head">
        <h1>Dashboard</h1>
        <p className="page__sub">What needs you now, and what&apos;s coming up.</p>
      </header>
      <SetupReminder />
      {dashboard.isPending && <Loading />}
      {dashboard.isError && (
        <Notice tone="danger" live>
          {dashboard.error.message}
        </Notice>
      )}
      {dashboard.data && (
        <div className="dashboard">
          <Queue
            title="Payments to verify"
            hint="Check each UTR against your bank or UPI app before confirming."
            rows={dashboard.data.to_verify}
            empty="Nothing waiting. Nice."
            actions={['confirm', 'reject']}
            tone="urgent"
          />
          <Queue
            title="Requests to approve"
            rows={dashboard.data.to_approve}
            empty="No requests to approve."
            actions={['confirm', 'reject']}
            tone="urgent"
          />
          <Queue title="Today" rows={dashboard.data.today} empty="No sessions today." />
          <Queue title="Coming up" rows={dashboard.data.upcoming} empty="Nothing booked yet." />
        </div>
      )}
    </div>
  );
}
