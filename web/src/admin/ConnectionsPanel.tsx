import { useState } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { fieldErrors } from './forms';
import {
  useGoogleCalendars,
  useGoogleConnect,
  useGoogleDisconnect,
  useIntegrations,
  useSaveCalendars,
} from './settingsHooks';
import { TelegramBox } from './TelegramBox';
import type { GoogleCalendarOption, Integrations } from './types';

function CalendarChoice({
  providerId,
  calendars,
  google,
}: {
  providerId: number;
  calendars: GoogleCalendarOption[];
  google: Integrations['google'];
}) {
  const save = useSaveCalendars(providerId);
  const [busy, setBusy] = useState<string[]>(google.busy_calendar_ids ?? []);
  const [target, setTarget] = useState(
    google.target_calendar_id ?? calendars.find((c) => c.primary)?.id ?? '',
  );
  const errors = fieldErrors(save.error);

  return (
    <div className="stack">
      <fieldset className="checks">
        <legend>Block booking times when these calendars are busy</legend>
        {calendars.map((c) => (
          <label key={c.id} className="check">
            <input
              type="checkbox"
              checked={busy.includes(c.id)}
              onChange={(e) =>
                setBusy(
                  calendars
                    .map((x) => x.id)
                    .filter((id) => (id === c.id ? e.target.checked : busy.includes(id))),
                )
              }
            />
            <span>{c.summary}</span>
          </label>
        ))}
        {errors.busy && <p className="field__error">{errors.busy}</p>}
      </fieldset>
      <Field label="Add sessions to" error={errors.target}>
        <select className="input" value={target} onChange={(e) => setTarget(e.target.value)}>
          {calendars
            .filter((c) => c.writable)
            .map((c) => (
              <option key={c.id} value={c.id}>
                {c.summary}
              </option>
            ))}
        </select>
      </Field>
      <div className="form-actions">
        <Button onClick={() => save.mutate({ busy, target })} disabled={save.isPending}>
          Save calendars
        </Button>
        {save.isSuccess && (
          <span className="saved" role="status">
            Saved.
          </span>
        )}
      </div>
    </div>
  );
}

function GoogleBox({ providerId, google }: { providerId: number; google: Integrations['google'] }) {
  const connect = useGoogleConnect(providerId);
  const disconnect = useGoogleDisconnect(providerId);
  const calendars = useGoogleCalendars(providerId, google.connected && google.active === true);
  const [email, setEmail] = useState(google.account_email ?? '');
  const [asking, setAsking] = useState(false);
  const errors = fieldErrors(connect.error);

  if (!google.configured) {
    return (
      <p className="hint">
        Google Calendar isn't set up on this server yet (see INSTALL.md › Google Calendar).
      </p>
    );
  }

  return (
    <div className="stack">
      {google.connected ? (
        <p>
          {google.active ? (
            <Badge tone="success">Connected</Badge>
          ) : (
            <Badge tone="danger">Access lost</Badge>
          )}{' '}
          as <strong>{google.account_email}</strong>
        </p>
      ) : (
        <p className="hint">
          Check this provider's Google Calendar for clashes and add confirmed sessions there, with a
          Meet link.
        </p>
      )}
      {google.connected && google.active === false && (
        <Notice tone="warn">
          Google access was removed. Bookings still work, without the clash check, until you connect
          again.
        </Notice>
      )}
      {calendars.data && (
        <CalendarChoice providerId={providerId} calendars={calendars.data} google={google} />
      )}
      {calendars.isError && (
        <Notice tone="danger" live>
          {calendars.error.message}
        </Notice>
      )}
      {(!google.connected || google.active === false) &&
        (connect.data ? (
          <Notice tone="info" title={`Open this signed in as ${email.trim().toLowerCase()}`}>
            <p>
              <a className="btn" href={connect.data.url} target="_blank" rel="noreferrer">
                Open Google to connect
              </a>
            </p>
            <p className="cell-sub">
              It works once, for 30 minutes, and only for that Google account.
            </p>
          </Notice>
        ) : (
          <div className="form-row form-row--end">
            <Field label="Google account email" error={errors.email}>
              <input
                className="input"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </Field>
            <Button
              variant="secondary"
              disabled={connect.isPending}
              onClick={() => connect.mutate({ email: email.trim(), replace: google.connected })}
            >
              Get a Google link
            </Button>
          </div>
        ))}
      {google.connected &&
        (asking ? (
          <div className="booking-buttons__ask">
            <p>Disconnect Google Calendar? Bookings stay open, without the clash check.</p>
            <div className="booking-buttons__row">
              <Button
                className="btn--danger"
                onClick={() => disconnect.mutate(undefined, { onSettled: () => setAsking(false) })}
              >
                Yes, disconnect
              </Button>
              <Button variant="secondary" onClick={() => setAsking(false)}>
                Keep it
              </Button>
            </div>
          </div>
        ) : (
          <Button variant="ghost" onClick={() => setAsking(true)}>
            Disconnect
          </Button>
        ))}
      {connect.isError && Object.keys(errors).length === 0 && (
        <Notice tone="danger" live>
          {connect.error.message}
        </Notice>
      )}
    </div>
  );
}

export function ConnectionsPanel({ providerId }: { providerId: number }) {
  const integrations = useIntegrations(providerId);
  if (integrations.isPending) return <Loading />;
  if (integrations.isError) {
    return (
      <Notice tone="danger" live>
        {integrations.error.message}
      </Notice>
    );
  }

  return (
    <div className="form-grid">
      <section className="form-section" aria-labelledby="tg-h">
        <h2 id="tg-h" className="form-section__title">
          Telegram alerts
        </h2>
        <TelegramBox
          providerId={providerId}
          configured={integrations.data.telegram.configured}
          linked={integrations.data.telegram.linked}
        />
      </section>
      <section className="form-section" aria-labelledby="google-h">
        <h2 id="google-h" className="form-section__title">
          Google Calendar
        </h2>
        <GoogleBox providerId={providerId} google={integrations.data.google} />
      </section>
    </div>
  );
}
