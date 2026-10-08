import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Loading, Notice } from '../design/components/Notice';
import { useAdmin } from './context';
import { fieldErrors, orNull } from './forms';
import {
  useChangePassword,
  useMyNotifications,
  useMyTelegram,
  useRenameMe,
  useSaveMyNotifications,
} from './settingsHooks';
import { TelegramBox } from './TelegramBox';

const MIN_PASSWORD = 10;

function NameForm() {
  const { user } = useAdmin();
  const rename = useRenameMe();
  const [name, setName] = useState(user.name ?? '');
  const errors = fieldErrors(rename.error);
  return (
    <form
      className="form-section"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        rename.mutate(orNull(name));
      }}
    >
      <h2 className="form-section__title">Name</h2>
      <Field label="Your name" hint={`Signed in as ${user.email}.`} error={errors.name}>
        <input
          className="input"
          value={name}
          maxLength={120}
          onChange={(e) => setName(e.target.value)}
        />
      </Field>
      <div className="form-actions">
        <Button type="submit" disabled={rename.isPending}>
          Save name
        </Button>
        {rename.isSuccess && (
          <span className="saved" role="status">
            Saved.
          </span>
        )}
      </div>
    </form>
  );
}

function PasswordForm() {
  const change = useChangePassword();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const errors = fieldErrors(change.error);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (next.length < MIN_PASSWORD) {
      setProblem(`Use at least ${MIN_PASSWORD} characters.`);
      return;
    }
    setProblem(null);
    change.mutate(
      { current_password: current, new_password: next },
      { onSuccess: () => setCurrent('') },
    );
  };

  return (
    <form className="form-section" onSubmit={submit} noValidate>
      <h2 className="form-section__title">Password</h2>
      <Field label="Current password" error={errors.current_password}>
        <input
          className="input"
          type="password"
          autoComplete="current-password"
          value={current}
          onChange={(e) => setCurrent(e.target.value)}
        />
      </Field>
      <Field
        label="New password"
        hint={`At least ${MIN_PASSWORD} characters.`}
        error={problem ?? errors.new_password}
      >
        <input
          className="input"
          type="password"
          autoComplete="new-password"
          value={next}
          onChange={(e) => setNext(e.target.value)}
        />
      </Field>
      <div className="form-actions">
        <Button type="submit" disabled={change.isPending}>
          Change password
        </Button>
      </div>
      {change.isSuccess && (
        <Notice tone="success" live>
          Password changed. You've been signed out on your other devices.
        </Notice>
      )}
    </form>
  );
}

function MyTelegram() {
  const telegram = useMyTelegram();
  return (
    <section className="form-section" aria-labelledby="my-telegram-h">
      <h2 id="my-telegram-h" className="form-section__title">
        Telegram
      </h2>
      {telegram.isPending ? (
        <Loading />
      ) : (
        <TelegramBox
          providerId={null}
          configured={telegram.data?.configured ?? false}
          linked={telegram.data?.linked ?? false}
        />
      )}
    </section>
  );
}

function BookingEmails() {
  const state = useMyNotifications();
  const save = useSaveMyNotifications();
  if (state.isPending) return <Loading />;
  if (!state.data?.applies) return null;
  return (
    <section className="form-section" aria-labelledby="booking-emails-h">
      <h2 id="booking-emails-h" className="form-section__title">
        Booking emails
      </h2>
      <label className="check">
        <input
          type="checkbox"
          checked={state.data.booking_emails}
          disabled={save.isPending}
          onChange={(e) => save.mutate(e.target.checked)}
        />
        <span>Email me about bookings</span>
      </label>
      <p className="hint">
        Turn this off if a provider's booking email (for example a shared bookings inbox) already
        gets them, so you don't get every email twice. If no booking email is set, you still get
        them.
      </p>
      {save.isError && (
        <Notice tone="danger" live>
          Couldn't save that. Please try again.
        </Notice>
      )}
    </section>
  );
}

export function AccountPage() {
  const { user } = useAdmin();
  return (
    <div className="page">
      <header className="page__head">
        <h1>My account</h1>
      </header>
      <div className="form-grid">
        <NameForm />
        <PasswordForm />
        <MyTelegram />
        {user.role === 'owner' && <BookingEmails />}
      </div>
    </div>
  );
}
