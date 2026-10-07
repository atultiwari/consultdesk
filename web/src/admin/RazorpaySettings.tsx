import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Badge, Notice } from '../design/components/Notice';
import { fieldErrors } from './forms';
import { useAdminProviders } from './hooks';
import {
  useCheckOrgKeys,
  useOfferEverywhere,
  useNewWebhookSecret,
  useRemoveOrgKeys,
  useRemoveProviderKeys,
  useSaveOrgKeys,
  useSaveProviderKeys,
} from './paymentHooks';
import type { PaymentSettings } from './types';

function CopyField({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <div className="copy-field">
      <span className="filter__label">{label}</span>
      <span className="copy-field__row">
        <code className="mono copy-field__value">{value}</code>
        <Button
          variant="secondary"
          aria-label={`Copy ${label}`}
          onClick={() =>
            void navigator.clipboard?.writeText(value).then(
              () => setCopied(true),
              () => setCopied(false),
            )
          }
        >
          {copied ? 'Copied' : 'Copy'}
        </Button>
      </span>
    </div>
  );
}

/** Where to point Razorpay's webhook, with the secret when it has just been made. */
export function WebhookSetup({ url, secret }: { url: string; secret?: string }) {
  return (
    <div className="stack webhook-setup">
      <p className="hint">
        In the Razorpay Dashboard (Test Mode), open{' '}
        <strong>Account &amp; Settings → Webhooks → Add new webhook</strong>, paste this URL
        {secret ? ' and secret' : ''}, tick <strong>payment_link.paid</strong>, and save.
      </p>
      <CopyField label="Webhook URL" value={url} />
      {secret && (
        <>
          <CopyField label="Webhook secret" value={secret} />
          <Notice tone="warn" title="Copy the secret now">
            It is shown only this once. If you lose it, make a new one and update the webhook in
            Razorpay.
          </Notice>
        </>
      )}
    </div>
  );
}

function KeysForm({
  onSave,
  pending,
  error,
  submitLabel,
}: {
  onSave: (keys: { key_id: string; key_secret: string }) => void;
  pending: boolean;
  error: unknown;
  submitLabel: string;
}) {
  const [keyId, setKeyId] = useState('');
  const [secret, setSecret] = useState('');
  const errors = fieldErrors(error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    onSave({ key_id: keyId.trim(), key_secret: secret.trim() });
  };

  return (
    <form className="stack" onSubmit={submit} noValidate>
      <Field
        label="Key ID"
        hint="Razorpay Dashboard → Account & Settings → API Keys (Test Mode). Starts with rzp_test_."
        error={errors.key_id}
      >
        <input
          className="input mono"
          value={keyId}
          autoComplete="off"
          spellCheck={false}
          onChange={(e) => setKeyId(e.target.value)}
        />
      </Field>
      <Field
        label="Key Secret"
        hint="Stored encrypted; nobody can read it back, including you."
        error={errors.key_secret}
      >
        <input
          className="input mono"
          type="password"
          value={secret}
          autoComplete="off"
          onChange={(e) => setSecret(e.target.value)}
        />
      </Field>
      <div className="form-actions">
        <Button type="submit" disabled={pending}>
          {submitLabel}
        </Button>
      </div>
    </form>
  );
}

export function OrgRazorpay({ settings }: { settings: PaymentSettings }) {
  const save = useSaveOrgKeys();
  const check = useCheckOrgKeys();
  const rotate = useNewWebhookSecret();
  const remove = useRemoveOrgKeys();
  const offer = useOfferEverywhere();
  const [replacing, setReplacing] = useState(false);
  const [asking, setAsking] = useState(false);
  const r = settings.razorpay;
  const fromEnv = r.source === 'env';
  const freshSecret = save.data?.razorpay.webhook_secret ?? rotate.data?.razorpay.webhook_secret;
  const problem = [check, rotate, remove, offer].find((m) => m.isError)?.error;

  return (
    <section className="panel stack" aria-labelledby="rzp-h">
      <h2 id="rzp-h">Razorpay (organisation account)</h2>
      <p className="hint">
        Online payments go to this account unless a teacher has their own below. Each booking gets
        its own payment link for the exact amount.
      </p>
      {r.configured && !replacing ? (
        <>
          <p>
            <Badge tone={r.mode === 'test' ? 'warn' : 'success'}>
              {r.mode === 'test' ? 'Test mode' : 'Live'}
            </Badge>{' '}
            Key ID <span className="mono">{r.key_id}</span>
          </p>
          {fromEnv ? (
            <p className="hint">
              These keys come from the server’s .env file, so they survive a database reset. Save
              other keys here to use those instead.
            </p>
          ) : (
            r.env_key_id && (
              <p className="hint">
                Saved here, so they’re used instead of the keys in the server’s .env file (
                <span className="mono">{r.env_key_id}</span>). Removing them goes back to those.
              </p>
            )
          )}
          {check.isSuccess && (
            <Notice tone="success" live>
              Razorpay accepted these keys.
            </Notice>
          )}
          {problem && (
            <Notice tone="danger" live>
              {problem.message}
            </Notice>
          )}
          <div className="offer-everywhere">
            <p className="hint">
              Online payment shows only on sessions where “Razorpay payment link” is ticked. Tick it
              on every paid session in rupees that doesn’t need approval:
            </p>
            <Button variant="secondary" onClick={() => offer.mutate()} disabled={offer.isPending}>
              Offer online payment on every paid session
            </Button>
            {offer.isSuccess && (
              <Notice tone="success" live>
                {offer.data.sessions_updated === 0
                  ? 'Every eligible session already offers online payment.'
                  : `Online payment added to ${offer.data.sessions_updated} ${offer.data.sessions_updated === 1 ? 'session' : 'sessions'}.`}
              </Notice>
            )}
          </div>
          <WebhookSetup url={r.webhook_url} secret={freshSecret} />
          {!r.has_webhook_secret && !freshSecret && (
            <Notice tone="warn">No webhook secret yet: make one below.</Notice>
          )}
          <div className="form-actions">
            <Button variant="secondary" onClick={() => check.mutate()} disabled={check.isPending}>
              Check connection
            </Button>
            {!fromEnv && (
              <Button
                variant="secondary"
                onClick={() => rotate.mutate()}
                disabled={rotate.isPending}
              >
                New webhook secret
              </Button>
            )}
            <Button variant="ghost" onClick={() => setReplacing(true)}>
              {fromEnv ? 'Use other keys' : 'Replace keys'}
            </Button>
            {fromEnv ? null : asking ? (
              <>
                <Button
                  className="btn--danger"
                  onClick={() => remove.mutate(undefined, { onSettled: () => setAsking(false) })}
                >
                  Yes, remove keys
                </Button>
                <Button variant="secondary" onClick={() => setAsking(false)}>
                  Keep them
                </Button>
              </>
            ) : (
              <Button variant="ghost" onClick={() => setAsking(true)}>
                Remove keys
              </Button>
            )}
          </div>
        </>
      ) : (
        <KeysForm
          submitLabel="Save keys"
          pending={save.isPending}
          error={save.error}
          onSave={(keys) => save.mutate(keys, { onSuccess: () => setReplacing(false) })}
        />
      )}
      {r.live_allowed ? (
        r.mode === 'test' && (
          <p className="hint">
            Real payments are switched on for this server: replace these with your Live Mode keys
            (rzp_live_…) when you’re ready.
          </p>
        )
      ) : (
        <p className="hint">
          Only Test Mode keys (rzp_test_…) are accepted. To take real payments, add PAYMENTS_LIVE=1
          to the server’s config.php (see the deployment guide).
        </p>
      )}
    </section>
  );
}

export function TeacherAccounts({ settings }: { settings: PaymentSettings }) {
  const providers = useAdminProviders();
  const save = useSaveProviderKeys();
  const remove = useRemoveProviderKeys();
  const [providerId, setProviderId] = useState('');
  const withOwn = new Set(settings.overrides.map((o) => o.provider_id));

  return (
    <section className="panel stack" aria-labelledby="teacher-rzp-h">
      <h2 id="teacher-rzp-h">Teachers with their own Razorpay account</h2>
      <p className="hint">
        Their sessions are paid straight into their own account. Everyone else uses the
        organisation's.
      </p>
      {settings.overrides.length === 0 ? (
        <p className="cell-sub">None yet.</p>
      ) : (
        <ul className="rows">
          {settings.overrides.map((o) => (
            <li key={o.provider_id} className="rows__item">
              <span className="rows__main">
                <span className="cell-main">{o.provider_name}</span>
                <span className="cell-sub mono">{o.key_id}</span>
              </span>
              <Button
                variant="ghost"
                aria-label={`Stop using ${o.provider_name}'s own account`}
                onClick={() => remove.mutate(o.provider_id)}
              >
                Use the organisation's
              </Button>
            </li>
          ))}
        </ul>
      )}
      <Field label="Teacher">
        <select
          className="input"
          value={providerId}
          onChange={(e) => setProviderId(e.target.value)}
        >
          <option value="">Choose…</option>
          {providers.data
            ?.filter((p) => !withOwn.has(p.id))
            .map((p) => (
              <option key={p.id} value={String(p.id)}>
                {p.name}
              </option>
            ))}
        </select>
      </Field>
      {providerId !== '' && (
        <KeysForm
          submitLabel="Save their keys"
          pending={save.isPending}
          error={save.error}
          onSave={(keys) =>
            save.mutate(
              { providerId: Number(providerId), ...keys },
              { onSuccess: () => setProviderId('') },
            )
          }
        />
      )}
      {save.data && (
        <WebhookSetup url={settings.razorpay.webhook_url} secret={save.data.webhook_secret} />
      )}
    </section>
  );
}
