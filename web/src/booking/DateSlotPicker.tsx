import { useMemo, useState } from 'react';
import { useSlots } from '../api/hooks';
import type { Slot } from '../lib/time';
import {
  addDays,
  canonicalTimezone,
  dayKey,
  formatDay,
  formatTime,
  groupSlots,
  nextDays,
  timezoneLabel,
} from '../lib/time';
import { Button } from '../design/components/Button';
import { Loading, Notice } from '../design/components/Notice';

const DAYS_SHOWN = 7;
const MAX_WEEKS_AHEAD = 8;
const PERIOD_LABEL = { morning: 'Morning', afternoon: 'Afternoon', evening: 'Evening' } as const;

function timezones(current: string): string[] {
  try {
    // Some browsers leave UTC out of the list; always include the visitor's own zone.
    return [
      ...new Set([current, ...Intl.supportedValuesOf('timeZone').map(canonicalTimezone)]),
    ].sort();
  } catch {
    return [
      'UTC',
      'Asia/Kolkata',
      'Asia/Dubai',
      'Asia/Singapore',
      'Europe/London',
      'Europe/Berlin',
      'America/New_York',
      'America/Los_Angeles',
      'Australia/Sydney',
    ];
  }
}

type Props = {
  provider: string;
  service: string;
  timezone: string;
  onTimezoneChange: (tz: string) => void;
  selected: Slot | null;
  onSelect: (slot: Slot) => void;
};

export function DateSlotPicker({
  provider,
  service,
  timezone,
  onTimezoneChange,
  selected,
  onSelect,
}: Props) {
  const today = dayKey(new Date(), timezone);
  // Coming back from a later step, reopen the week that holds the chosen time.
  const [weekStart, setWeekStart] = useState(() =>
    selected ? dayKey(new Date(selected.start), timezone) : today,
  );
  const [day, setDay] = useState<string | null>(
    selected ? dayKey(new Date(selected.start), timezone) : null,
  );
  const [showZones, setShowZones] = useState(false);

  // Provider-local dates can differ from the visitor's by a day, so ask for a day either side.
  const slots = useSlots(provider, service, addDays(weekStart, -1), addDays(weekStart, DAYS_SHOWN));
  const days = useMemo(
    () => new Map(groupSlots(slots.data?.slots ?? [], timezone).map((d) => [d.date, d])),
    [slots.data, timezone],
  );
  const week = nextDays(weekStart, DAYS_SHOWN);

  // The chosen day if it is in this week and has openings, otherwise the first day that does.
  const activeDay =
    day !== null && week.includes(day) && days.has(day)
      ? day
      : (week.find((d) => days.has(d)) ?? null);
  const current = activeDay ? days.get(activeDay) : undefined;
  const lastWeekStart = addDays(today, DAYS_SHOWN * MAX_WEEKS_AHEAD);

  return (
    <div className="picker">
      <div className="picker__tz">
        <p>
          Times in <strong>{timezoneLabel(timezone)}</strong>
        </p>
        <Button variant="ghost" onClick={() => setShowZones((v) => !v)} aria-expanded={showZones}>
          Change
        </Button>
      </div>
      {showZones && (
        <label className="picker__tz-select">
          <span className="visually-hidden">Your timezone</span>
          <select
            className="input"
            value={timezone}
            onChange={(e) => onTimezoneChange(e.target.value)}
          >
            {timezones(timezone).map((tz) => (
              <option key={tz} value={tz}>
                {tz.replace(/_/g, ' ')}
              </option>
            ))}
          </select>
        </label>
      )}

      <div className="week">
        <div className="week__nav">
          <Button
            variant="secondary"
            onClick={() => setWeekStart(addDays(weekStart, -DAYS_SHOWN))}
            disabled={weekStart <= today}
            aria-label="Previous week"
          >
            ←
          </Button>
          <p className="week__range" aria-live="polite">
            {formatDay(week[0]).slice(4)} – {formatDay(week[DAYS_SHOWN - 1]).slice(4)}
          </p>
          <Button
            variant="secondary"
            onClick={() => setWeekStart(addDays(weekStart, DAYS_SHOWN))}
            disabled={weekStart >= lastWeekStart}
            aria-label="Next week"
          >
            →
          </Button>
        </div>
        <fieldset className="week__days">
          <legend className="visually-hidden">Day</legend>
          {week.map((date) => {
            const count = days.get(date)?.periods.reduce((n, p) => n + p.slots.length, 0) ?? 0;
            return (
              <label key={date} className={`week__day${count === 0 ? ' week__day--empty' : ''}`}>
                <input
                  type="radio"
                  name="day"
                  value={date}
                  checked={activeDay === date}
                  disabled={count === 0}
                  onChange={() => setDay(date)}
                />
                <span className="week__weekday">{formatDay(date).split(' ')[0]}</span>
                <span className="week__date">{Number(date.slice(8))}</span>
                <span
                  className="week__count"
                  aria-label={count === 0 ? 'no open times' : `${count} open times`}
                >
                  {count === 0 ? '·' : count}
                </span>
              </label>
            );
          })}
        </fieldset>
      </div>

      <div className="picker__slots">
        {slots.isPending || slots.isPlaceholderData ? (
          <Loading label="Finding open times…" />
        ) : slots.isError ? (
          <Notice tone="danger" title="Couldn't load times" live>
            {slots.error.message}
          </Notice>
        ) : current === undefined ? (
          <Notice title="No open times this week">
            Try the next week{' '}
            <Button variant="ghost" onClick={() => setWeekStart(addDays(weekStart, DAYS_SHOWN))}>
              Show next week →
            </Button>
          </Notice>
        ) : (
          <fieldset className="slots" aria-busy={slots.isFetching}>
            <legend className="slots__legend">{formatDay(current.date)}</legend>
            {current.periods.map(({ period, slots: periodSlots }) => (
              <div key={period} className="slots__period">
                <p className="slots__period-label">{PERIOD_LABEL[period]}</p>
                <div className="slots__grid">
                  {periodSlots.map((slot, i) => (
                    <label
                      key={slot.start}
                      className="slot"
                      style={{ animationDelay: `${Math.min(i, 8) * 20}ms` }}
                    >
                      <input
                        type="radio"
                        name="slot"
                        value={slot.start}
                        checked={selected?.start === slot.start}
                        onChange={() => onSelect(slot)}
                      />
                      <span>{formatTime(slot.start, timezone)}</span>
                    </label>
                  ))}
                </div>
              </div>
            ))}
          </fieldset>
        )}
      </div>
    </div>
  );
}
