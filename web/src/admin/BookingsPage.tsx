import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import { Button } from '../design/components/Button';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { visitorTimezone } from '../lib/time';
import { BookingCodes } from './BookingCodes';
import { isStaff, useAdmin } from './context';
import { formatMoney, formatWhen, STATUS_LABEL, STATUS_TONE, statusLabel } from './format';
import { useAdminProviders, useBookingList } from './hooks';
import type { BookingStatus } from './types';

const PER_PAGE = 25;
const FILTERS = ['provider', 'status', 'q'] as const;

function ProviderFilter({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const providers = useAdminProviders();
  return (
    <label className="filter">
      <span className="filter__label">Provider</span>
      <select className="input" value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">Everyone</option>
        {providers.data?.map((p) => (
          <option key={p.id} value={String(p.id)}>
            {p.name}
          </option>
        ))}
      </select>
    </label>
  );
}

export function BookingsPage() {
  const { base, user } = useAdmin();
  const [params, setParams] = useSearchParams();
  const [search, setSearch] = useState(params.get('q') ?? '');
  const page = Math.max(1, Number(params.get('page') ?? 1) || 1);

  const query = new URLSearchParams();
  FILTERS.forEach((key) => {
    const value = params.get(key);
    if (value) query.set(key, value);
  });
  query.set('page', String(page));
  query.set('per_page', String(PER_PAGE));
  const list = useBookingList(query.toString());
  const total = list.data?.meta?.total ?? 0;
  const tz = visitorTimezone();

  /** Changing a filter starts again from the first page. */
  const setFilter = (key: (typeof FILTERS)[number], value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    next.delete('page');
    setParams(next);
  };
  const goTo = (target: number) => {
    const next = new URLSearchParams(params);
    next.set('page', String(target));
    setParams(next);
  };
  const submitSearch = (event: FormEvent) => {
    event.preventDefault();
    setFilter('q', search.trim());
  };

  return (
    <div className="page">
      <header className="page__head">
        <h1>Bookings</h1>
        {isStaff(user) && <BookingCodes />}
      </header>
      <form className="filters" role="search" onSubmit={submitSearch}>
        <label className="filter filter--grow">
          <span className="filter__label">Search</span>
          <input
            className="input"
            type="search"
            placeholder="Reference, name, email, phone or UTR"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </label>
        <label className="filter">
          <span className="filter__label">Status</span>
          <select
            className="input"
            value={params.get('status') ?? ''}
            onChange={(e) => setFilter('status', e.target.value)}
          >
            <option value="">Any status</option>
            {(Object.keys(STATUS_LABEL) as BookingStatus[]).map((status) => (
              <option key={status} value={status}>
                {STATUS_LABEL[status]}
              </option>
            ))}
          </select>
        </label>
        {isStaff(user) && (
          <ProviderFilter
            value={params.get('provider') ?? ''}
            onChange={(v) => setFilter('provider', v)}
          />
        )}
      </form>

      {list.isPending && <Loading />}
      {list.isError && (
        <Notice tone="danger" live>
          {list.error.message}
        </Notice>
      )}
      {list.data && list.data.data.length === 0 && (
        <p className="empty">{total > 0 ? 'No bookings on this page.' : 'No bookings match.'}</p>
      )}
      {list.data && list.data.data.length > 0 && (
        <div className="table-wrap">
          <table className="table" aria-label="Bookings">
            <thead>
              <tr>
                <th scope="col">Ref</th>
                <th scope="col">When</th>
                <th scope="col">Customer</th>
                <th scope="col">Session</th>
                <th scope="col" className="num">
                  Amount
                </th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              {list.data.data.map((row) => (
                <tr key={row.id}>
                  <td>
                    <Link className="mono" to={`${base}/bookings/${row.id}`}>
                      {row.ref}
                    </Link>
                  </td>
                  <td>
                    <time dateTime={row.start}>{formatWhen(row.start, tz)}</time>
                  </td>
                  <td>
                    <span className="cell-main">{row.customer_name}</span>
                    <span className="cell-sub">{row.customer_email}</span>
                  </td>
                  <td>
                    <span className="cell-main">{row.service_title}</span>
                    {isStaff(user) && <span className="cell-sub">{row.provider.name}</span>}
                  </td>
                  <td className="num">
                    {row.amount_minor > 0 ? formatMoney(row.amount_minor, row.currency) : 'Free'}
                  </td>
                  <td>
                    <Badge tone={STATUS_TONE[row.status]}>{statusLabel(row)}</Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {total > 0 && (
        <nav className="pager" aria-label="Pages">
          <p>
            {Math.min((page - 1) * PER_PAGE + 1, total)}–{Math.min(page * PER_PAGE, total)} of{' '}
            {total}
          </p>
          <Button
            variant="secondary"
            disabled={page <= 1}
            onClick={() => goTo(page - 1)}
            aria-label="Previous page"
          >
            ←
          </Button>
          <Button
            variant="secondary"
            disabled={page * PER_PAGE >= total}
            onClick={() => goTo(page + 1)}
            aria-label="Next page"
          >
            →
          </Button>
        </nav>
      )}
    </div>
  );
}
