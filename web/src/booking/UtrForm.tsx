import { useState, type FormEvent } from 'react';
import { ApiError } from '../api/client';
import { useSubmitUtr } from '../api/hooks';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';

const UTR = /^\d{12}$/;

export function UtrForm({ refCode, token }: { refCode: string; token: string }) {
  const submit = useSubmitUtr(refCode, token);
  const [utr, setUtr] = useState('');
  const [error, setError] = useState<string | undefined>();

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    const digits = utr.replace(/\s+/g, '');
    if (!UTR.test(digits)) {
      setError('The UTR is the 12-digit number in your UPI app’s payment details.');
      return;
    }
    setError(undefined);
    submit.mutate(digits, {
      onError: (e) =>
        setError(
          e instanceof ApiError
            ? (e.fields.utr ?? e.message)
            : 'Something went wrong. Please try again.',
        ),
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
      <Button type="submit" disabled={submit.isPending}>
        {submit.isPending ? 'Sending…' : 'I’ve paid — submit UTR'}
      </Button>
    </form>
  );
}
