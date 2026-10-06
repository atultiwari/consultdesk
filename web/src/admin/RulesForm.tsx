import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Notice } from '../design/components/Notice';
import { fieldErrors } from './forms';
import { useUpdateProvider } from './hooks';
import type { AdminProvider, BookingRules } from './types';

type Draft = Record<
  | 'notice_hours'
  | 'horizon_days'
  | 'buffer_before'
  | 'buffer_after'
  | 'slot_interval'
  | 'max_per_day',
  string
>;

const draftOf = (r: BookingRules): Draft => ({
  notice_hours: String(r.min_notice_min / 60),
  horizon_days: String(r.horizon_days),
  buffer_before: String(r.buffer_before),
  buffer_after: String(r.buffer_after),
  slot_interval: String(r.slot_interval),
  max_per_day: r.max_per_day === null ? '' : String(r.max_per_day),
});

/** Whole numbers only; anything else is sent as-is for the server to reject with a message. */
const toNumber = (value: string): number | string =>
  /^\d+(\.\d+)?$/.test(value.trim()) ? Number(value) : value;

export function RulesForm({ provider }: { provider: AdminProvider }) {
  const update = useUpdateProvider(provider.id);
  const [draft, setDraft] = useState(() => draftOf(provider.rules));
  const errors = fieldErrors(update.error);
  const input = (key: keyof Draft, extra: { min?: number; max?: number; step?: number } = {}) => ({
    className: 'input input--short',
    type: 'number',
    inputMode: 'numeric' as const,
    value: draft[key],
    ...extra,
    onChange: (e: { target: { value: string } }) => {
      update.reset();
      setDraft((d) => ({ ...d, [key]: e.target.value }));
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const notice = toNumber(draft.notice_hours);
    update.mutate({
      rules: {
        min_notice_min: typeof notice === 'number' ? Math.round(notice * 60) : notice,
        horizon_days: toNumber(draft.horizon_days),
        buffer_before: toNumber(draft.buffer_before),
        buffer_after: toNumber(draft.buffer_after),
        slot_interval: toNumber(draft.slot_interval),
        max_per_day: draft.max_per_day.trim() === '' ? null : toNumber(draft.max_per_day),
      },
    });
  };

  return (
    <form className="form-grid" onSubmit={submit} noValidate>
      <fieldset className="form-section">
        <legend>When people can book</legend>
        <Field
          label="Minimum notice"
          hint="Hours before a session that booking closes."
          error={errors['rules.min_notice_min']}
        >
          <input {...input('notice_hours', { min: 0, step: 0.5 })} />
        </Field>
        <Field
          label="Book up to"
          hint="Days ahead the calendar is open."
          error={errors['rules.horizon_days']}
        >
          <input {...input('horizon_days', { min: 1, max: 365 })} />
        </Field>
        <Field
          label="Start times every"
          hint="Minutes between possible start times."
          error={errors['rules.slot_interval']}
        >
          <input {...input('slot_interval', { min: 5, max: 1440, step: 5 })} />
        </Field>
      </fieldset>
      <fieldset className="form-section">
        <legend>Breathing room</legend>
        <Field
          label="Gap before a session"
          hint="Minutes kept free before each session."
          error={errors['rules.buffer_before']}
        >
          <input {...input('buffer_before', { min: 0, max: 240 })} />
        </Field>
        <Field
          label="Gap after a session"
          hint="Minutes kept free after each session."
          error={errors['rules.buffer_after']}
        >
          <input {...input('buffer_after', { min: 0, max: 240 })} />
        </Field>
        <Field
          label="Most sessions in a day"
          hint="Leave empty for no limit."
          error={errors['rules.max_per_day']}
        >
          <input {...input('max_per_day', { min: 1, max: 1000 })} />
        </Field>
      </fieldset>
      <div className="form-actions">
        <Button type="submit" disabled={update.isPending}>
          Save rules
        </Button>
        {update.isSuccess && (
          <span className="saved" role="status">
            Saved.
          </span>
        )}
        {update.isError && Object.keys(errors).length === 0 && (
          <Notice tone="danger" live>
            {update.error.message}
          </Notice>
        )}
      </div>
    </form>
  );
}
