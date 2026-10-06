import { useId, type ReactElement, type ReactNode } from 'react';
import { cloneElement } from 'react';

type Props = {
  label: ReactNode;
  hint?: ReactNode;
  error?: string;
  optional?: boolean;
  /** A single input/select/textarea; id and aria attributes are wired up automatically. */
  children: ReactElement<Record<string, unknown>>;
};

export function Field({ label, hint, error, optional, children }: Props) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;

  return (
    <div className="field">
      <label className="field__label" htmlFor={id}>
        {label}
        {optional && <span className="field__optional"> (optional)</span>}
      </label>
      {hint && (
        <p className="field__hint" id={hintId}>
          {hint}
        </p>
      )}
      {cloneElement(children, {
        id,
        'aria-describedby': describedBy,
        'aria-invalid': error ? true : undefined,
      })}
      {error && (
        <p className="field__error" id={errorId} role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
