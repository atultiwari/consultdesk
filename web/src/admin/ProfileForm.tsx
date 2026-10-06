import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Notice } from '../design/components/Notice';
import { isStaff, useAdmin } from './context';
import { fieldErrors, orNull, timezones } from './forms';
import { useUpdateProvider } from './hooks';
import type { AdminProvider } from './types';

type Draft = {
  name: string;
  title: string;
  bio: string;
  slug: string;
  active: boolean;
  timezone: string;
  notify_email: string;
  whatsapp: string;
  upi_vpa: string;
  upi_payee_name: string;
};

const draftOf = (p: AdminProvider): Draft => ({
  name: p.name,
  title: p.title ?? '',
  bio: p.bio ?? '',
  slug: p.slug,
  active: p.active,
  timezone: p.timezone,
  notify_email: p.notify_email ?? '',
  whatsapp: p.whatsapp ?? '',
  upi_vpa: p.upi_vpa ?? '',
  upi_payee_name: p.upi_payee_name ?? '',
});

export function ProfileForm({ provider }: { provider: AdminProvider }) {
  const { user } = useAdmin();
  const staff = isStaff(user);
  const update = useUpdateProvider(provider.id);
  const [draft, setDraft] = useState(() => draftOf(provider));
  const errors = fieldErrors(update.error);
  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => {
    update.reset();
    setDraft((d) => ({ ...d, [key]: value }));
  };
  const text = (key: Exclude<keyof Draft, 'active'>) => ({
    className: 'input',
    value: draft[key],
    onChange: (e: { target: { value: string } }) => set(key, e.target.value),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    update.mutate({
      name: draft.name.trim(),
      title: orNull(draft.title),
      bio: orNull(draft.bio),
      timezone: draft.timezone,
      notify_email: orNull(draft.notify_email),
      whatsapp: orNull(draft.whatsapp),
      upi_vpa: orNull(draft.upi_vpa),
      upi_payee_name: orNull(draft.upi_payee_name),
      ...(staff ? { slug: draft.slug.trim(), active: draft.active } : {}),
    });
  };

  return (
    <form className="form-grid" onSubmit={submit} noValidate>
      <fieldset className="form-section">
        <legend>About</legend>
        <Field label="Name" error={errors.name}>
          <input {...text('name')} />
        </Field>
        <Field label="Title" hint="Shown under the name, e.g. Pathologist." error={errors.title}>
          <input {...text('title')} />
        </Field>
        <Field label="About them" error={errors.bio}>
          <textarea {...text('bio')} rows={4} />
        </Field>
        {staff && (
          <>
            <Field label="Web address" hint={`Booking page: /p/${draft.slug}`} error={errors.slug}>
              <input {...text('slug')} className="input mono" />
            </Field>
            <label className="check">
              <input
                type="checkbox"
                checked={draft.active}
                onChange={(e) => set('active', e.target.checked)}
              />
              <span>Taking bookings (shown on the site)</span>
            </label>
          </>
        )}
        <Field label="Timezone" hint="Weekly hours are in this timezone." error={errors.timezone}>
          <select {...text('timezone')}>
            {timezones(draft.timezone).map((tz) => (
              <option key={tz}>{tz}</option>
            ))}
          </select>
        </Field>
      </fieldset>

      <fieldset className="form-section">
        <legend>Contact</legend>
        <Field
          label="Notification email"
          hint="New bookings and payments to verify are sent here."
          error={errors.notify_email}
        >
          <input {...text('notify_email')} type="email" />
        </Field>
        <Field
          label="WhatsApp number"
          hint="Customers can message this number about a payment."
          error={errors.whatsapp}
        >
          <input {...text('whatsapp')} type="tel" placeholder="+91 …" />
        </Field>
      </fieldset>

      <fieldset className="form-section">
        <legend>UPI payments</legend>
        <Field
          label="UPI ID"
          hint="Customers pay this ID; you then check the UTR they send."
          error={errors.upi_vpa}
        >
          <input
            {...text('upi_vpa')}
            className="input mono"
            autoComplete="off"
            spellCheck={false}
          />
        </Field>
        <Field label="Payee name" hint="As it appears in UPI apps." error={errors.upi_payee_name}>
          <input {...text('upi_payee_name')} />
        </Field>
      </fieldset>

      <div className="form-actions">
        <Button type="submit" disabled={update.isPending}>
          Save profile
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
