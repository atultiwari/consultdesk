import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Notice } from '../design/components/Notice';
import { adminFetch } from './api';
import { fieldErrors } from './forms';

type Codes = { prefix: string; default: string };
const KEY = ['admin', 'booking-codes'] as const;

/** What booking codes start with (VRL in VRL-7F3K). Owners and admins only; new bookings only. */
export function BookingCodes() {
  const client = useQueryClient();
  const codes = useQuery({
    queryKey: KEY,
    queryFn: ({ signal }) => adminFetch<Codes>('/booking-codes', { signal }),
  });
  const save = useMutation({
    mutationFn: (prefix: string) =>
      adminFetch<Codes>('/booking-codes', { method: 'PUT', json: { prefix } }),
    onSuccess: (saved) => client.setQueryData(KEY, saved),
  });
  const [draft, setDraft] = useState<string | null>(null);
  const errors = fieldErrors(save.error);
  if (!codes.data) return null;
  const value = draft ?? codes.data.prefix;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate(value.trim().toUpperCase().replace(/-+$/, ''), { onSuccess: () => setDraft(null) });
  };

  return (
    <details className="booking-codes">
      <summary>
        Booking codes start with <span className="mono">{codes.data.prefix}-</span>
      </summary>
      <form className="booking-codes__form" onSubmit={submit} noValidate>
        <Field
          label="Prefix"
          hint={`2–6 letters or digits. Changes new bookings only; leave empty for ${codes.data.default}.`}
          error={errors.prefix}
        >
          <input
            className="input input--short mono"
            maxLength={7}
            autoComplete="off"
            value={value}
            onChange={(e) => setDraft(e.target.value.toUpperCase())}
          />
        </Field>
        <Button type="submit" variant="secondary" disabled={save.isPending}>
          Save prefix
        </Button>
        {save.isSuccess && (
          <Notice tone="success" live>
            Saved. New bookings will look like {save.data.prefix}-7F3K.
          </Notice>
        )}
        {save.isError && !errors.prefix && (
          <Notice tone="danger" live>
            {save.error.message}
          </Notice>
        )}
      </form>
    </details>
  );
}
