import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Loading, Notice } from '../design/components/Notice';
import { visitorTimezone } from '../lib/time';
import { useAdmin } from './context';
import { formatMoney } from './format';
import { fieldErrors, orNull, timezones } from './forms';
import {
  useAddStarterSessions,
  useCompleteSetup,
  useSaveTeacher,
  useSetMode,
  useSetup,
} from './setupHooks';
import type { SetupState, StarterTemplate } from './types';

const STEPS = [
  { key: 'mode', label: 'How it’s used' },
  { key: 'teacher', label: 'Teacher' },
  { key: 'sessions', label: 'Starter sessions' },
  { key: 'done', label: 'Done' },
] as const;
type Step = (typeof STEPS)[number]['key'];

function firstOpenStep(state: SetupState): Step {
  if (state.mode === null) return 'mode';
  if (state.provider === null) return 'teacher';
  return 'sessions';
}

function ModeStep({ state, onDone }: { state: SetupState; onDone: () => void }) {
  const setMode = useSetMode();
  const [mode, setChoice] = useState(state.mode ?? 'single');
  const options = [
    {
      value: 'single',
      title: 'Just me',
      text: 'One teacher, for example a personal website. Visitors go straight to your sessions.',
    },
    {
      value: 'multi',
      title: 'Several teachers',
      text: 'An academy or practice with more than one teacher. Add teachers any time.',
    },
  ] as const;

  return (
    <div className="stack">
      <h2 className="setup__title">Who will people book with?</h2>
      <div className="presets" role="radiogroup" aria-label="Who will people book with?">
        {options.map((o) => (
          <label key={o.value} className="preset">
            <input
              type="radio"
              name="mode"
              value={o.value}
              checked={mode === o.value}
              onChange={() => setChoice(o.value)}
            />
            <span>
              <span className="cell-main">{o.title}</span>
              <span className="cell-sub">{o.text}</span>
            </span>
          </label>
        ))}
      </div>
      <p className="hint">A one-teacher site can switch to several teachers later, here.</p>
      {setMode.isError && (
        <Notice tone="danger" live>
          {setMode.error.message}
        </Notice>
      )}
      <div className="form-actions">
        <Button
          onClick={() => setMode.mutate(mode, { onSuccess: onDone })}
          disabled={setMode.isPending}
        >
          Continue
        </Button>
      </div>
    </div>
  );
}

function TeacherStep({ state, onDone }: { state: SetupState; onDone: () => void }) {
  const { user } = useAdmin();
  const save = useSaveTeacher();
  const p = state.provider;
  const [form, setForm] = useState({
    name: p?.name ?? '',
    title: p?.title ?? '',
    bio: p?.bio ?? '',
    timezone: p?.timezone ?? visitorTimezone(),
    notify_email: p?.notify_email ?? user.email,
    whatsapp: p?.whatsapp ?? '',
    upi_vpa: p?.upi_vpa ?? '',
    upi_payee_name: p?.upi_payee_name ?? '',
  });
  const errors = fieldErrors(save.error);
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate(
      {
        name: form.name.trim(),
        title: orNull(form.title),
        bio: orNull(form.bio),
        timezone: form.timezone,
        notify_email: orNull(form.notify_email),
        whatsapp: orNull(form.whatsapp),
        upi_vpa: orNull(form.upi_vpa),
        upi_payee_name: orNull(form.upi_payee_name),
      },
      { onSuccess: onDone },
    );
  };

  return (
    <form className="stack" onSubmit={submit} noValidate>
      <h2 className="setup__title">
        {state.mode === 'single' ? 'About you' : 'Your first teacher'}
      </h2>
      <p className="hint">
        This is what customers see on the booking page. You can change it, and add a photo, any
        time.
      </p>
      <div className="form-grid">
        <fieldset className="form-section">
          <legend>Profile</legend>
          <Field label="Name" error={errors.name}>
            <input className="input" value={form.name} onChange={set('name')} autoComplete="name" />
          </Field>
          <Field
            label="Title"
            hint="Shown under the name, e.g. Pathologist · AI researcher."
            error={errors.title}
          >
            <input className="input" value={form.title} onChange={set('title')} />
          </Field>
          <Field label="About" hint="A few lines about who you help and how." error={errors.bio}>
            <textarea className="input" rows={4} value={form.bio} onChange={set('bio')} />
          </Field>
          <Field label="Timezone" error={errors.timezone}>
            <select className="input" value={form.timezone} onChange={set('timezone')}>
              {timezones(form.timezone).map((tz) => (
                <option key={tz}>{tz}</option>
              ))}
            </select>
          </Field>
        </fieldset>
        <fieldset className="form-section">
          <legend>Contact and payment</legend>
          <Field label="Email for new bookings" error={errors.notify_email}>
            <input
              className="input"
              type="email"
              value={form.notify_email}
              onChange={set('notify_email')}
            />
          </Field>
          <Field
            label="WhatsApp number"
            hint="Optional. Customers can message it about a payment."
            error={errors.whatsapp}
          >
            <input className="input" type="tel" value={form.whatsapp} onChange={set('whatsapp')} />
          </Field>
          <Field
            label="UPI ID"
            hint="Optional. Customers pay this ID and send the UTR."
            error={errors.upi_vpa}
          >
            <input
              className="input mono"
              value={form.upi_vpa}
              onChange={set('upi_vpa')}
              autoComplete="off"
              spellCheck={false}
            />
          </Field>
          <Field label="Payee name" hint="As shown in UPI apps." error={errors.upi_payee_name}>
            <input className="input" value={form.upi_payee_name} onChange={set('upi_payee_name')} />
          </Field>
        </fieldset>
      </div>
      {save.isError && Object.keys(errors).length === 0 && (
        <Notice tone="danger" live>
          {save.error.message}
        </Notice>
      )}
      <div className="form-actions">
        <Button type="submit" disabled={save.isPending}>
          Save and continue
        </Button>
      </div>
    </form>
  );
}

type Choice = { title: string; duration: string; price: string };

function TemplateCard({
  template,
  choice,
  onChange,
}: {
  template: StarterTemplate;
  choice: Choice | null;
  onChange: (c: Choice | null) => void;
}) {
  return (
    <fieldset className={choice ? 'starter starter--on' : 'starter'} aria-label={template.title}>
      <label className="check starter__pick">
        <input
          type="checkbox"
          checked={choice !== null}
          aria-label={`Offer this: ${template.title}`}
          onChange={(e) =>
            onChange(
              e.target.checked
                ? {
                    title: template.title,
                    duration: String(template.duration_min),
                    price: String(template.price_minor / 100),
                  }
                : null,
            )
          }
        />
        <span>
          <span className="cell-main">{template.title}</span>
          <span className="cell-sub">
            {template.duration_min} min ·{' '}
            {template.price_minor === 0 ? 'Free' : formatMoney(template.price_minor, 'INR')}
            {template.requires_approval && ' · you approve each booking'}
          </span>
          <span className="cell-sub">{template.tagline}</span>
        </span>
      </label>
      {choice && (
        <div className="starter__edit">
          <Field label="Title">
            <input
              className="input"
              value={choice.title}
              onChange={(e) => onChange({ ...choice, title: e.target.value })}
            />
          </Field>
          <Field label="Length (minutes)">
            <input
              className="input input--short"
              type="number"
              min={5}
              step={5}
              value={choice.duration}
              onChange={(e) => onChange({ ...choice, duration: e.target.value })}
            />
          </Field>
          <Field label="Price (₹)" hint={template.price_note ?? undefined}>
            <input
              className="input input--short"
              type="number"
              min={0}
              value={choice.price}
              onChange={(e) => onChange({ ...choice, price: e.target.value })}
            />
          </Field>
        </div>
      )}
    </fieldset>
  );
}

/** Blank or nonsense numbers would otherwise reach the server as 0 (free) or the template's value. */
function choiceProblem(c: Choice): string | null {
  const price = Number(c.price);
  const duration = Number(c.duration);
  if (c.price.trim() === '' || !Number.isFinite(price) || price < 0)
    return 'Enter a price (0 for free)';
  if (c.duration.trim() === '' || !Number.isInteger(duration) || duration < 5)
    return 'Enter a length of at least 5 minutes';
  return null;
}

function SessionsStep({ state, onDone }: { state: SetupState; onDone: () => void }) {
  const add = useAddStarterSessions();
  const [choices, setChoices] = useState<Record<string, Choice>>({});
  const picked = Object.entries(choices);
  const [problem, setProblem] = useState<string | null>(null);
  const errors = fieldErrors(add.error);
  const submit = () => {
    const invalid = picked.find(([, c]) => choiceProblem(c) !== null);
    setProblem(
      invalid
        ? `${choiceProblem(invalid[1])} for “${invalid[1].title.trim() || 'this session'}”.`
        : null,
    );
    if (invalid) return;
    add.mutate(
      picked.map(([key, c]) => ({
        key,
        title: c.title.trim(),
        duration_min: Number(c.duration),
        price_minor: Math.round(Number(c.price) * 100),
      })),
      { onSuccess: onDone },
    );
  };

  return (
    <div className="stack">
      <h2 className="setup__title">Starter sessions</h2>
      <p className="hint">
        Pick the ones you want to offer, and change their title, length and price. Prices are
        suggestions from research on what people in India pay for similar sessions. Each is added
        with the usual questions customers answer, and you can edit everything later under Sessions.
      </p>
      {state.template_sets.map((set) => (
        <section key={set.key} className="starter-set" aria-labelledby={`set-${set.key}`}>
          <h3 id={`set-${set.key}`}>{set.label}</h3>
          <p className="cell-sub">{set.description}</p>
          <div className="starter-grid">
            {set.templates.map((t) => (
              <TemplateCard
                key={t.key}
                template={t}
                choice={choices[t.key] ?? null}
                onChange={(c) =>
                  setChoices((all) =>
                    c === null
                      ? Object.fromEntries(Object.entries(all).filter(([key]) => key !== t.key))
                      : { ...all, [t.key]: c },
                  )
                }
              />
            ))}
          </div>
        </section>
      ))}
      {problem !== null && (
        <Notice tone="danger" live>
          {problem}
        </Notice>
      )}
      {add.isError && (
        <Notice tone="danger" live>
          {Object.values(errors)[0] ?? add.error.message}
        </Notice>
      )}
      <div className="form-actions form-actions--sticky">
        <Button onClick={submit} disabled={add.isPending || picked.length === 0}>
          {`Add ${picked.length} ${picked.length === 1 ? 'session' : 'sessions'}`}
        </Button>
        <Button
          variant="secondary"
          onClick={() => add.mutate([], { onSuccess: onDone })}
          disabled={add.isPending}
        >
          Skip — I’ll make my own
        </Button>
      </div>
    </div>
  );
}

function DoneStep({ state }: { state: SetupState }) {
  const { base } = useAdmin();
  const complete = useCompleteSetup();
  const navigate = useNavigate();
  const provider = state.provider;

  return (
    <div className="stack">
      <h2 className="setup__title">You’re ready to take bookings</h2>
      <p>
        A starter week of hours has been added (weekday mornings and evenings, Saturday morning) if
        there wasn’t one.
      </p>
      {provider && (
        <ul className="setup__next">
          <li>
            <a href={`/p/${provider.slug}`} target="_blank" rel="noreferrer">
              See the booking page
            </a>
          </li>
          <li>
            <Link to={`${base}/providers/${provider.id}?tab=hours`}>Adjust weekly hours</Link>
          </li>
          <li>
            <Link to={`${base}/providers/${provider.id}?tab=sessions`}>
              Edit sessions and their questions
            </Link>
          </li>
          <li>
            <Link to={`${base}/payments`}>Set up online payments</Link>
          </li>
        </ul>
      )}
      <div className="form-actions">
        <Button
          onClick={() => complete.mutate(undefined, { onSuccess: () => void navigate(base) })}
          disabled={complete.isPending}
        >
          Finish
        </Button>
      </div>
    </div>
  );
}

export function SetupPage() {
  const setup = useSetup();
  const [params, setParams] = useSearchParams();

  if (setup.isPending) return <Loading />;
  if (setup.isError) {
    return (
      <Notice tone="danger" live>
        {setup.error.message}
      </Notice>
    );
  }
  const state = setup.data;
  const requested = STEPS.find((s) => s.key === params.get('step'))?.key;
  const step: Step = requested ?? firstOpenStep(state);
  const go = (next: Step) => setParams({ step: next });

  return (
    <div className="page setup">
      <header className="page__head">
        <h1>Set up your site</h1>
        <p className="page__sub">
          A few questions to get you taking bookings. You can change all of it later.
        </p>
      </header>
      <ol className="setup__steps" aria-label="Steps">
        {STEPS.map((s, i) => (
          <li
            key={s.key}
            aria-current={s.key === step ? 'step' : undefined}
            className={s.key === step ? 'is-current' : undefined}
          >
            <span className="setup__num" aria-hidden="true">
              {i + 1}
            </span>
            {s.label}
          </li>
        ))}
      </ol>
      <div className="panel">
        {step === 'mode' && <ModeStep state={state} onDone={() => go('teacher')} />}
        {step === 'teacher' && <TeacherStep state={state} onDone={() => go('sessions')} />}
        {step === 'sessions' && <SessionsStep state={state} onDone={() => go('done')} />}
        {step === 'done' && <DoneStep state={state} />}
      </div>
    </div>
  );
}
