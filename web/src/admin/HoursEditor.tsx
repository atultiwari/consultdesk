import { useState } from 'react';
import { Button } from '../design/components/Button';
import { Loading, Notice } from '../design/components/Notice';
import { WEEKDAYS } from './format';
import { fieldErrors } from './forms';
import { useHours, useSaveHours, useServices } from './hooks';
import type { HoursWindow } from './types';

type Row = HoursWindow & { key: number };

let nextKey = 0;
const withKey = (w: HoursWindow): Row => {
  nextKey += 1;
  return { ...w, key: nextKey };
};
const byTime = (a: Row, b: Row) => a.weekday - b.weekday || a.start.localeCompare(b.start);

type Props = { providerId: number; timezone: string };

/** The week as one form: windows per weekday, saved together. */
export function HoursEditor({ providerId, timezone }: Props) {
  const hours = useHours(providerId);
  if (hours.isError) {
    return (
      <Notice tone="danger" live>
        {hours.error.message}
      </Notice>
    );
  }
  return hours.data ? (
    <HoursForm providerId={providerId} timezone={timezone} initial={hours.data} />
  ) : (
    <Loading />
  );
}

function HoursForm({ providerId, timezone, initial }: Props & { initial: HoursWindow[] }) {
  const services = useServices(providerId);
  const save = useSaveHours(providerId);
  const [rows, setRows] = useState<Row[]>(() => initial.map(withKey));
  const errors = fieldErrors(save.error);

  const change = (key: number, patch: Partial<HoursWindow>) => {
    save.reset();
    setRows(rows.map((r) => (r.key === key ? { ...r, ...patch } : r)));
  };
  const add = (weekday: number) => {
    save.reset();
    setRows([...rows, withKey({ weekday, start: '09:00', end: '17:00', service_id: null })]);
  };
  const remove = (key: number) => {
    save.reset();
    setRows(rows.filter((r) => r.key !== key));
  };
  const invalid = rows.some((r) => r.end <= r.start);
  const sorted = [...rows].sort(byTime);

  return (
    <form
      className="hours"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        if (!invalid) {
          save.mutate(
            sorted.map(({ weekday, start, end, service_id }) => ({
              weekday,
              start,
              end,
              service_id,
            })),
          );
        }
      }}
    >
      <p className="hours__intro">
        Times are in {timezone}. A window for one session replaces the general hours for that
        session.
      </p>
      {WEEKDAYS.map((day, index) => {
        const weekday = index + 1;
        const windows = sorted.filter((r) => r.weekday === weekday);
        return (
          <fieldset key={day} className="hours__day">
            <legend>{day}</legend>
            <div className="hours__windows">
              {windows.length === 0 && <p className="hours__closed">Closed</p>}
              {windows.map((w) => {
                const position = sorted.indexOf(w);
                return (
                  <div key={w.key} className="hours__window">
                    <input
                      className="input input--time"
                      type="time"
                      step={300}
                      aria-label={`${day} from`}
                      value={w.start}
                      onChange={(e) => change(w.key, { start: e.target.value })}
                    />
                    <span aria-hidden="true">–</span>
                    <input
                      className="input input--time"
                      type="time"
                      step={300}
                      aria-label={`${day} until`}
                      aria-invalid={
                        w.end <= w.start || errors[`rules.${position}`] ? true : undefined
                      }
                      value={w.end}
                      onChange={(e) => change(w.key, { end: e.target.value })}
                    />
                    {(services.data?.length ?? 0) > 0 && (
                      <select
                        className="input input--for"
                        aria-label={`${day} ${w.start}–${w.end} applies to`}
                        value={w.service_id ?? ''}
                        onChange={(e) =>
                          change(w.key, {
                            service_id: e.target.value === '' ? null : Number(e.target.value),
                          })
                        }
                      >
                        <option value="">All sessions</option>
                        {services.data?.map((s) => (
                          <option key={s.id} value={s.id}>
                            Only {s.title}
                          </option>
                        ))}
                      </select>
                    )}
                    <Button
                      variant="ghost"
                      aria-label={`Remove ${w.start}–${w.end}`}
                      onClick={() => remove(w.key)}
                    >
                      Remove
                    </Button>
                    {(w.end <= w.start || errors[`rules.${position}`]) && (
                      <p className="field__error">
                        {errors[`rules.${position}`] ?? 'The end must be after the start.'}
                      </p>
                    )}
                  </div>
                );
              })}
              <Button
                variant="ghost"
                aria-label={`Add hours on ${day}`}
                onClick={() => add(weekday)}
              >
                + Add hours
              </Button>
            </div>
          </fieldset>
        );
      })}
      <div className="form-actions">
        <Button type="submit" disabled={save.isPending || invalid}>
          Save hours
        </Button>
        {save.isSuccess && (
          <span className="saved" role="status">
            Saved.
          </span>
        )}
        {save.isError && Object.keys(errors).length === 0 && (
          <Notice tone="danger" live>
            {save.error.message}
          </Notice>
        )}
      </div>
    </form>
  );
}
