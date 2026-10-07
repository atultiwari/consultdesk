import { useEffect, useRef, useState } from 'react';
import { countries, joinPhone, splitPhone, toE164 } from '../../lib/phone';

type Props = {
  value: string;
  onChange: (e164: string) => void;
  onBlur?: () => void;
  /** Set by <Field>: they go on the number box, which the label names. */
  id?: string;
  'aria-describedby'?: string;
  'aria-invalid'?: boolean;
  name?: string;
  autoComplete?: string;
};

/**
 * A country picker (flag, name and calling code) next to the number, so nobody has to wonder whether
 * to type +91, 091 or 91. The value is E.164, e.g. +919876543210.
 */
export function PhoneInput({
  value,
  onChange,
  onBlur,
  id,
  name,
  autoComplete = 'tel-national',
  ...aria
}: Props) {
  const list = countries(); // built once, then cached
  // The parent owns the E.164 value; this keeps what's being typed until it parses cleanly.
  const [country, setCountry] = useState(() => splitPhone(value).country);
  const [national, setNational] = useState(() => splitPhone(value).national);
  const emitted = useRef(value);
  const emit = (next: string) => {
    emitted.current = next;
    onChange(next);
  };

  useEffect(() => {
    if (value === emitted.current) return;
    // Changed from outside (a reset, another record): show it.
    const split = splitPhone(value);
    setCountry(split.country);
    setNational(split.national);
    emitted.current = value;
  }, [value]);

  useEffect(() => {
    // A number saved before country codes were required ("98765 43210") is shown with the default
    // country; hand that form back so saving doesn't trip over the old value.
    const normalised = joinPhone(country, national);
    if (value !== '' && normalised !== '' && normalised !== value) emit(normalised);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps
  const dial = list.find((c) => c.code === country)?.dial ?? '';

  return (
    <div className="phone-input">
      <select
        className="input phone-input__country"
        aria-label="Country code"
        value={country}
        onChange={(e) => {
          const next = e.target.value as typeof country;
          setCountry(next);
          emit(joinPhone(next, national));
        }}
      >
        {list.map((c) => (
          <option key={c.code} value={c.code}>
            {c.flag} {c.name} (+{c.dial})
          </option>
        ))}
      </select>
      <span className="phone-input__dial" aria-hidden="true">
        +{dial}
      </span>
      <input
        id={id}
        name={name}
        className="input phone-input__number"
        type="tel"
        inputMode="tel"
        autoComplete={autoComplete}
        placeholder="98765 43210"
        value={national}
        aria-describedby={aria['aria-describedby']}
        aria-invalid={aria['aria-invalid']}
        onChange={(e) => {
          setNational(e.target.value);
          emit(toE164(country, e.target.value));
        }}
        onBlur={() => {
          // A full international number ("+44 …") moves its code into the picker once typed.
          if (/^\s*(\+|00)/.test(national)) {
            const split = splitPhone(toE164(country, national), country);
            setCountry(split.country);
            setNational(split.national);
          }
          onBlur?.();
        }}
      />
    </div>
  );
}
