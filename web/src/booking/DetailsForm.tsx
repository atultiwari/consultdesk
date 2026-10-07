import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect } from 'react';
import { Controller, useForm } from 'react-hook-form';
import type { Question } from '../api/types';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { PhoneInput } from '../design/components/PhoneInput';
import { buildDetailsSchema, type DetailsValues } from '../lib/details';

type Props = {
  questions: Question[];
  initial: DetailsValues | null;
  serverErrors: Record<string, string>;
  onBack: () => void;
  onSubmit: (values: DetailsValues) => void;
};

function emptyAnswers(questions: Question[]): Record<string, string | boolean> {
  return Object.fromEntries(questions.map((q) => [q.id, q.type === 'checkbox' ? false : '']));
}

export function DetailsForm({ questions, initial, serverErrors, onBack, onSubmit }: Props) {
  const form = useForm<DetailsValues>({
    resolver: zodResolver(buildDetailsSchema(questions)) as never,
    defaultValues: initial ?? { name: '', email: '', phone: '', answers: emptyAnswers(questions) },
    mode: 'onTouched',
  });
  const { register, handleSubmit, formState, setError } = form;

  useEffect(() => {
    for (const [field, message] of Object.entries(serverErrors)) {
      setError(field as keyof DetailsValues, { message }, { shouldFocus: true });
    }
  }, [serverErrors, setError]);

  const error = (path: string): string | undefined => {
    const [head, tail] = path.split('.');
    const node = tail
      ? (formState.errors.answers as Record<string, { message?: string }> | undefined)?.[tail]
      : formState.errors[head as keyof DetailsValues];
    return (node as { message?: string } | undefined)?.message;
  };

  return (
    <form className="details" onSubmit={handleSubmit(onSubmit)} noValidate>
      <Field label="Full name" error={error('name')}>
        <input className="input" autoComplete="name" {...register('name')} />
      </Field>
      <Field
        label="Email"
        hint="Your confirmation and booking link go here."
        error={error('email')}
      >
        <input
          className="input"
          type="email"
          autoComplete="email"
          inputMode="email"
          {...register('email')}
        />
      </Field>
      <Controller
        control={form.control}
        name="phone"
        render={({ field }) => (
          <Field
            label="WhatsApp number"
            hint="Choose your country, then type the number."
            error={error('phone')}
          >
            <PhoneInput value={field.value} onChange={field.onChange} onBlur={field.onBlur} />
          </Field>
        )}
      />

      {questions.map((q) =>
        q.type === 'checkbox' ? (
          <div key={q.id} className="field">
            <label className="check">
              <input
                type="checkbox"
                {...register(`answers.${q.id}`)}
                aria-invalid={error(`answers.${q.id}`) ? true : undefined}
              />
              <span>{q.label}</span>
            </label>
            {error(`answers.${q.id}`) && (
              <p className="field__error" role="alert">
                {error(`answers.${q.id}`)}
              </p>
            )}
          </div>
        ) : (
          <Field key={q.id} label={q.label} optional={!q.required} error={error(`answers.${q.id}`)}>
            {q.type === 'textarea' ? (
              <textarea className="input" {...register(`answers.${q.id}`)} />
            ) : q.type === 'select' ? (
              <select className="input" {...register(`answers.${q.id}`)}>
                <option value="">Choose…</option>
                {q.options?.map((option) => (
                  <option key={option} value={option}>
                    {option}
                  </option>
                ))}
              </select>
            ) : (
              <input
                className="input"
                type={q.type === 'url' ? 'url' : 'text'}
                inputMode={q.type === 'url' ? 'url' : undefined}
                {...register(`answers.${q.id}`)}
              />
            )}
          </Field>
        ),
      )}

      <div className="step-actions">
        <Button variant="secondary" onClick={onBack}>
          Back
        </Button>
        <Button type="submit">Continue</Button>
      </div>
    </form>
  );
}
