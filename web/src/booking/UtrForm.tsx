import { useState, type FormEvent } from 'react';
import { ApiError } from '../api/client';
import { useSubmitUtr } from '../api/hooks';
import { Button, ButtonAnchor } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Notice } from '../design/components/Notice';

const UTR = /^\d{12}$/;

type Props = { refCode: string; token: string; providerName: string; whatsappUrl: string | null };

export function UtrForm({ refCode, token, providerName, whatsappUrl }: Props) {
  const submit = useSubmitUtr(refCode, token);
  const [utr, setUtr] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [reused, setReused] = useState(false);

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    const digits = utr.replace(/\s+/g, '');
    setReused(false);
    if (!UTR.test(digits)) {
      setError('The UTR is the 12-digit number in your UPI app’s payment details.');
      return;
    }
    setError(undefined);
    submit.mutate(digits, {
      onError: (e) => {
        if (e instanceof ApiError && e.code === 'duplicate_utr') setReused(true);
        setError(
          e instanceof ApiError
            ? (e.fields.utr ?? e.message)
            : 'Something went wrong. Please try again.',
        );
      },
    });
  };

  return (
    <form className="utr" onSubmit={onSubmit} noValidate>
      <Field
        label="UPI reference (UTR)"
        hint="12 digits, shown as UTR, UPI Ref No. or Transaction ID in your UPI app."
        error={error}
      >
        <input
          className="input mono"
          inputMode="numeric"
          autoComplete="off"
          maxLength={16}
          placeholder="e.g. 4123 4567 8901"
          value={utr}
          onChange={(e) => setUtr(e.target.value)}
        />
      </Field>
      {reused && (
        <Notice tone="warn" title="That payment is already linked to a booking">
          A UPI payment can’t be reused for another booking, including one that expired. If you paid
          for this booking, check that you entered the right UTR; otherwise contact {providerName}{' '}
          with booking {refCode} and they’ll sort it out.
          {whatsappUrl && (
            <div className="outcome__action">
              <ButtonAnchor
                variant="secondary"
                href={whatsappUrl}
                target="_blank"
                rel="noopener noreferrer"
              >
                Message {providerName} on WhatsApp
              </ButtonAnchor>
            </div>
          )}
        </Notice>
      )}
      <Button type="submit" disabled={submit.isPending}>
        {submit.isPending ? 'Sending…' : 'I’ve paid — submit UTR'}
      </Button>
    </form>
  );
}
