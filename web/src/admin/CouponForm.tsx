import { TZDate } from '@date-fns/tz';
import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Notice } from '../design/components/Notice';
import { visitorTimezone } from '../lib/time';
import { isStaff, useAdmin } from './context';
import { useSaveCoupon } from './couponHooks';
import { fieldErrors, orNull } from './forms';
import { useAdminProviders, useServices } from './hooks';
import type { AdminCoupon, CouponKind } from './types';

/** "2026-11-15" as the start (or end) of that day where the person is, as UTC ISO. */
function dayEdge(date: string, end: boolean): string | null {
  if (date === '') return null;
  const [y, m, d] = date.split('-').map(Number);
  const zoned = end
    ? new TZDate(y, m - 1, d, 23, 59, 59, visitorTimezone())
    : new TZDate(y, m - 1, d, 0, 0, 0, visitorTimezone());
  return zoned.toISOString().replace('.000', '');
}

/** UTC ISO → "2026-11-15" in the person's timezone, for a date input. */
function dayOf(iso: string | null): string {
  if (iso === null) return '';
  return new Intl.DateTimeFormat('en-CA', { timeZone: visitorTimezone() }).format(new Date(iso));
}

export function CouponForm({ coupon, onDone }: { coupon: AdminCoupon | null; onDone: () => void }) {
  const { user } = useAdmin();
  const staff = isStaff(user);
  const providers = useAdminProviders(staff);
  const save = useSaveCoupon(coupon?.id ?? null);
  const errors = fieldErrors(save.error);

  const [code, setCode] = useState(coupon?.code ?? '');
  const [kind, setKind] = useState<CouponKind>(coupon?.kind ?? 'percent');
  const [value, setValue] = useState(
    coupon ? String(coupon.kind === 'amount' ? coupon.value / 100 : coupon.value) : '10',
  );
  const [providerId, setProviderId] = useState(
    staff ? String(coupon?.provider_id ?? '') : String(user.provider_id ?? ''),
  );
  const [serviceIds, setServiceIds] = useState<number[]>(coupon?.service_ids ?? []);
  const [from, setFrom] = useState(dayOf(coupon?.valid_from ?? null));
  const [until, setUntil] = useState(dayOf(coupon?.valid_until ?? null));
  const [maxUses, setMaxUses] = useState(coupon?.max_uses ? String(coupon.max_uses) : '');
  const [oncePerEmail, setOncePerEmail] = useState(coupon?.once_per_email ?? true);
  const [note, setNote] = useState(coupon?.note ?? '');
  const teacher = providerId === '' ? null : Number(providerId);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const amount = Number(value);
    save.mutate(
      {
        code: code.trim().toUpperCase(),
        kind,
        value: kind === 'amount' ? Math.round(amount * 100) : Math.round(amount),
        provider_id: teacher,
        service_ids: teacher !== null && serviceIds.length > 0 ? serviceIds : null,
        valid_from: dayEdge(from, false),
        valid_until: dayEdge(until, true),
        max_uses: maxUses.trim() === '' ? null : Number(maxUses),
        once_per_email: oncePerEmail,
        note: orNull(note),
      },
      { onSuccess: onDone },
    );
  };

  return (
    <form className="stack" onSubmit={submit} noValidate>
      {save.isError && Object.keys(errors).length === 0 && (
        <Notice tone="danger" live>
          {save.error.message}
        </Notice>
      )}
      <Field label="Code" hint="What customers type, e.g. WELCOME10." error={errors.code}>
        <input
          className="input input--short mono"
          autoComplete="off"
          maxLength={32}
          value={code}
          onChange={(e) => setCode(e.target.value.toUpperCase())}
        />
      </Field>
      <fieldset className="choice-row">
        <legend className="field__label">Discount</legend>
        <label>
          <input type="radio" checked={kind === 'percent'} onChange={() => setKind('percent')} /> %
          off
        </label>
        <label>
          <input type="radio" checked={kind === 'amount'} onChange={() => setKind('amount')} /> ₹
          off
        </label>
      </fieldset>
      <Field label={kind === 'percent' ? 'Percent off' : 'Rupees off'} error={errors.value}>
        <input
          className="input input--short"
          type="number"
          min={1}
          max={kind === 'percent' ? 100 : undefined}
          value={value}
          onChange={(e) => setValue(e.target.value)}
        />
      </Field>
      {staff && (
        <Field label="For" error={errors.provider_id}>
          <select
            className="input"
            value={providerId}
            onChange={(e) => {
              setProviderId(e.target.value);
              setServiceIds([]);
            }}
          >
            <option value="">Every teacher (site-wide)</option>
            {providers.data?.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </Field>
      )}
      {teacher !== null && (
        <SessionPicker providerId={teacher} chosen={serviceIds} onChange={setServiceIds} />
      )}
      {errors.service_ids && <p className="field__error">{errors.service_ids}</p>}
      <div className="field-pair">
        <Field label="From" optional error={errors.valid_from}>
          <input
            className="input"
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
          />
        </Field>
        <Field label="Until" optional error={errors.valid_until}>
          <input
            className="input"
            type="date"
            value={until}
            onChange={(e) => setUntil(e.target.value)}
          />
        </Field>
      </div>
      <Field
        label="Uses in total"
        optional
        hint="Leave empty for no limit."
        error={errors.max_uses}
      >
        <input
          className="input input--short"
          type="number"
          min={1}
          value={maxUses}
          onChange={(e) => setMaxUses(e.target.value)}
        />
      </Field>
      <label className="check">
        <input
          type="checkbox"
          checked={oncePerEmail}
          onChange={(e) => setOncePerEmail(e.target.checked)}
        />{' '}
        Once per customer (email address)
      </label>
      <Field label="Note for yourself" optional error={errors.note}>
        <input
          className="input"
          maxLength={200}
          value={note}
          onChange={(e) => setNote(e.target.value)}
        />
      </Field>
      <div className="form-actions">
        <Button type="submit" disabled={save.isPending}>
          {coupon ? 'Save coupon' : 'Create coupon'}
        </Button>
        <Button variant="secondary" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

function SessionPicker({
  providerId,
  chosen,
  onChange,
}: {
  providerId: number;
  chosen: number[];
  onChange: (ids: number[]) => void;
}) {
  const services = useServices(providerId);
  if (!services.data || services.data.length === 0) return null;
  return (
    <fieldset className="stack-tight">
      <legend className="field__label">Sessions</legend>
      <p className="hint">Leave all unticked for every session.</p>
      {services.data.map((s) => (
        <label key={s.id} className="check">
          <input
            type="checkbox"
            checked={chosen.includes(s.id)}
            onChange={(e) =>
              onChange(e.target.checked ? [...chosen, s.id] : chosen.filter((id) => id !== s.id))
            }
          />{' '}
          {s.title}
        </label>
      ))}
    </fieldset>
  );
}
