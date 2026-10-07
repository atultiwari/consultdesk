import { useState, type FormEvent } from 'react';
import { ApiError } from '../api/client';
import { useCheckCoupon } from '../api/hooks';
import type { CouponQuote } from '../api/types';
import { Button } from '../design/components/Button';

type Props = {
  provider: string;
  service: string;
  applied: CouponQuote | null;
  onChange: (quote: CouponQuote | null) => void;
};

/**
 * A small "Have a coupon?" link that opens into a code field. Deliberately quiet: most customers
 * won't have one, and it shouldn't send them off hunting for codes.
 */
export function CouponBox({ provider, service, applied, onChange }: Props) {
  const check = useCheckCoupon();
  const [open, setOpen] = useState(false);
  // Each state appears only after the person acted, so it can take focus without surprising anyone.
  const [acted, setActed] = useState(false);
  const [code, setCode] = useState('');
  const error =
    check.error instanceof ApiError
      ? (check.error.fields.code ?? check.error.message)
      : check.error
        ? 'Could not check the code. Try again.'
        : null;

  if (applied) {
    return (
      <p className="coupon coupon--applied" role="status">
        <span>
          <strong className="mono">{applied.code}</strong> applied: −{applied.discount_display}
        </span>
        <button
          type="button"
          className="link-button"
          autoFocus={acted}
          onClick={() => {
            setOpen(false);
            setCode('');
            onChange(null);
          }}
        >
          Remove
        </button>
      </p>
    );
  }

  if (!open) {
    return (
      <p className="coupon">
        <button
          type="button"
          className="link-button"
          autoFocus={acted}
          onClick={() => {
            setActed(true);
            setOpen(true);
          }}
        >
          Have a coupon?
        </button>
      </p>
    );
  }

  const apply = (event: FormEvent) => {
    event.preventDefault();
    if (code.trim() === '') return;
    check.mutate(
      { provider, service, code: code.trim().toUpperCase() },
      { onSuccess: (quote) => onChange(quote) },
    );
  };

  return (
    <form className="coupon coupon--form" onSubmit={apply} noValidate>
      <label className="coupon__label" htmlFor="coupon-code">
        Coupon code
      </label>
      <div className="coupon__row">
        <input
          id="coupon-code"
          className="input coupon__input"
          autoComplete="off"
          autoCapitalize="characters"
          spellCheck={false}
          maxLength={32}
          autoFocus
          value={code}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? 'coupon-error' : undefined}
          onChange={(e) => setCode(e.target.value)}
        />
        <Button type="submit" variant="secondary" disabled={check.isPending}>
          {check.isPending ? 'Checking…' : 'Apply'}
        </Button>
      </div>
      {error && (
        <p id="coupon-error" className="field__error" role="alert">
          {error}
        </p>
      )}
    </form>
  );
}
