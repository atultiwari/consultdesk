import { TZDate } from '@date-fns/tz';
import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Loading, Notice } from '../design/components/Notice';
import { addDays, visitorTimezone } from '../lib/time';
import { isStaff, useAdmin } from './context';
import { formatDate, formatWhen } from './format';
import { fieldErrors, orNull } from './forms';
import { useAdminProviders, useBlocked, useCreateBlocked, useDeleteBlocked } from './hooks';
import type { BlockedTime } from './types';

/** "2026-10-10" + "09:30" in Asia/Kolkata → "2026-10-10T09:30:00+05:30". */
function zonedIso(date: string, time: string, timeZone: string): string {
  const [y, m, d] = date.split('-').map(Number);
  const [h, min] = time.split(':').map(Number);
  return new TZDate(y, m - 1, d, h, min, 0, timeZone).toISOString().replace('.000', '');
}

function rangeText(block: BlockedTime, timeZone: string): string {
  if (block.all_day) {
    const lastDay = new Date(new Date(block.end).getTime() - 1).toISOString();
    const first = formatDate(block.start, timeZone);
    const last = formatDate(lastDay, timeZone);
    return first === last ? first : `${first} – ${last}`;
  }
  return `${formatWhen(block.start, timeZone)} – ${formatWhen(block.end, timeZone)}`;
}

function BlockForm() {
  const { user } = useAdmin();
  const staff = isStaff(user);
  const providers = useAdminProviders();
  const create = useCreateBlocked();
  const [providerId, setProviderId] = useState<string>(
    user.provider_id === null ? '' : String(user.provider_id),
  );
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [wholeDays, setWholeDays] = useState(true);
  const [startTime, setStartTime] = useState('09:00');
  const [endTime, setEndTime] = useState('17:00');
  const [reason, setReason] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const errors = fieldErrors(create.error);
  const chosen =
    providers.data?.find((p) => String(p.id) === providerId) ??
    (staff ? undefined : providers.data?.[0]);
  const timeZone = chosen?.timezone ?? visitorTimezone();

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const until = to || from;
    if (!from) {
      setProblem('Choose the first day.');
      return;
    }
    if (!wholeDays && (!/^\d{2}:\d{2}$/.test(startTime) || !/^\d{2}:\d{2}$/.test(endTime))) {
      setProblem('Choose a start and end time.');
      return;
    }
    const start = zonedIso(from, wholeDays ? '00:00' : startTime, timeZone);
    const end = wholeDays
      ? zonedIso(addDays(until, 1), '00:00', timeZone)
      : zonedIso(until, endTime, timeZone);
    setProblem(null);
    create.mutate(
      {
        provider_id: chosen ? chosen.id : null,
        start,
        end,
        all_day: wholeDays,
        reason: orNull(reason),
      },
      {
        onSuccess: () => {
          setFrom('');
          setTo('');
          setReason('');
        },
      },
    );
  };

  return (
    <form className="panel block-form" onSubmit={submit} noValidate>
      <h2>Block time off</h2>
      {(problem ??
        (create.isError && Object.keys(errors).length === 0 ? create.error.message : null)) && (
        <Notice tone="danger" live>
          {problem ?? create.error?.message}
        </Notice>
      )}
      {staff ? (
        <Field label="For" error={errors.provider_id}>
          <select
            className="input"
            value={providerId}
            onChange={(e) => setProviderId(e.target.value)}
          >
            <option value="">Everyone (the whole organisation)</option>
            {providers.data?.map((p) => (
              <option key={p.id} value={String(p.id)}>
                {p.name}
              </option>
            ))}
          </select>
        </Field>
      ) : (
        chosen && <p className="block-form__for">For {chosen.name}</p>
      )}
      <div className="form-row">
        <Field label="From" error={errors.start}>
          <input
            className="input"
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
          />
        </Field>
        <Field label="To" error={errors.end}>
          <input
            className="input"
            type="date"
            value={to}
            min={from || undefined}
            onChange={(e) => setTo(e.target.value)}
          />
        </Field>
      </div>
      <label className="check">
        <input
          type="checkbox"
          checked={wholeDays}
          onChange={(e) => setWholeDays(e.target.checked)}
        />
        <span>Whole days</span>
      </label>
      {!wholeDays && (
        <div className="form-row">
          <Field label="Starting at">
            <input
              className="input input--time"
              type="time"
              value={startTime}
              onChange={(e) => setStartTime(e.target.value)}
            />
          </Field>
          <Field label="Until">
            <input
              className="input input--time"
              type="time"
              value={endTime}
              onChange={(e) => setEndTime(e.target.value)}
            />
          </Field>
        </div>
      )}
      <Field label="Reason" hint="Only you and other admins see this." error={errors.reason}>
        <input
          className="input"
          value={reason}
          maxLength={255}
          onChange={(e) => setReason(e.target.value)}
        />
      </Field>
      <p className="hint">Times are in {timeZone}. Existing bookings are not cancelled.</p>
      <div className="form-actions">
        <Button type="submit" disabled={create.isPending || providers.isPending}>
          Block
        </Button>
      </div>
    </form>
  );
}

export function BlockedPage() {
  const { user } = useAdmin();
  const blocked = useBlocked();
  const remove = useDeleteBlocked();
  const providers = useAdminProviders();
  const zoneOf = (block: BlockedTime) =>
    providers.data?.find((p) => p.id === block.provider_id)?.timezone ?? visitorTimezone();

  return (
    <div className="page">
      <header className="page__head">
        <h1>Blocked times</h1>
        <p className="page__sub">Holidays, conferences and other time when no one can book.</p>
      </header>
      <div className="split">
        <BlockForm />
        <section className="panel" aria-labelledby="blocked-h">
          <h2 id="blocked-h">Coming up</h2>
          {blocked.isPending && <Loading />}
          {blocked.isError && (
            <Notice tone="danger" live>
              {blocked.error.message}
            </Notice>
          )}
          {remove.isError && (
            <Notice tone="danger" live>
              {remove.error.message}
            </Notice>
          )}
          {blocked.data?.length === 0 && <p className="empty">No time blocked.</p>}
          <ul className="rows">
            {blocked.data?.map((block) => (
              <li key={block.id} className="rows__item">
                <span className="rows__main">
                  <span className="cell-main">{block.reason ?? 'Blocked'}</span>
                  <span className="cell-sub">
                    {rangeText(block, zoneOf(block))} · {block.provider_name ?? 'Everyone'}
                  </span>
                </span>
                {(block.provider_id !== null || isStaff(user)) && (
                  <Button
                    variant="ghost"
                    aria-label={`Remove ${block.reason ?? rangeText(block, zoneOf(block))}`}
                    disabled={remove.isPending}
                    onClick={() => remove.mutate(block.id)}
                  >
                    Remove
                  </Button>
                )}
              </li>
            ))}
          </ul>
        </section>
      </div>
    </div>
  );
}
