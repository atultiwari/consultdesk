import { useState, type FormEvent } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Badge, Notice } from '../design/components/Notice';
import { fieldErrors } from './forms';
import {
  useCheckOrgKeys,
  useNewWebhookSecret,
  useRemoveOrgKeys,
  useSaveOrgKeys,
} from './paymentHooks';
import type { PaymentMode, PaymentSettings } from './types';

const MODE_NAME: Record<PaymentMode, string> = { test: 'Test Mode', live: 'Live Mode' };

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
export function WebhookSetup({
  url,
  secret,
  mode = 'test',
}: {
  url: string;
  secret?: string;
  mode?: PaymentMode;
}) {
  return (
    <div className="stack webhook-setup">
      <p className="hint">
        In the Razorpay Dashboard ({MODE_NAME[mode]}), open{' '}
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

export function KeysForm({
  onSave,
  pending,
  error,
  submitLabel,
  mode,
}: {
  onSave: (keys: { key_id: string; key_secret: string }) => void;
  pending: boolean;
  error: unknown;
  submitLabel: string;
  /** When set, only that mode's keys are accepted. */
  mode?: PaymentMode;
}) {
  const [keyId, setKeyId] = useState('');
  const [secret, setSecret] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const errors = fieldErrors(error);
  const prefix = mode ? `rzp_${mode}_` : null;
  const submit = (event: FormEvent) => {
    event.preventDefault();
    const id = keyId.trim();
    if (prefix && !id.startsWith(prefix)) {
      setProblem(`Use the ${MODE_NAME[mode ?? 'test']} Key ID: it starts with ${prefix}.`);
      return;
    }
    setProblem(null);
    onSave({ key_id: id, key_secret: secret.trim() });
  };

  return (
    <form className="stack" onSubmit={submit} noValidate>
      <Field
        label="Key ID"
        hint={
          mode
            ? `Razorpay Dashboard (${MODE_NAME[mode]}) → Account & Settings → API Keys. Starts with ${prefix}.`
            : 'Razorpay Dashboard → Account & Settings → API Keys. Starts with rzp_test_ or rzp_live_.'
        }
        error={problem ?? errors.key_id}
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

/** One set of the organisation's keys (test or live): add, check, rotate the webhook, remove. */
export function KeySet({ mode, settings }: { mode: PaymentMode; settings: PaymentSettings }) {
  const save = useSaveOrgKeys();
  const check = useCheckOrgKeys(mode);
  const rotate = useNewWebhookSecret(mode);
  const remove = useRemoveOrgKeys(mode);
  const [replacing, setReplacing] = useState(false);
  const [asking, setAsking] = useState(false);
  const r = settings.razorpay;
  const account = r.accounts[mode];
  const fromEnv = account?.source === 'env';
  const inUse = r.mode === mode;
  const freshSecret = save.data?.razorpay.webhook_secret ?? rotate.data?.razorpay.webhook_secret;
  const problem = [check, rotate, remove].find((m) => m.isError)?.error;
  const title = mode === 'test' ? 'Test keys' : 'Live keys';

  return (
    <section className="key-set stack" aria-label={title}>
      <h3 className="key-set__title">
        {title}{' '}
        {inUse ? (
          <Badge tone={mode === 'live' ? 'success' : 'warn'}>In use</Badge>
        ) : (
          <Badge>Not in use</Badge>
        )}
      </h3>
      <p className="hint">
        {mode === 'test'
          ? 'For trying bookings out: Razorpay’s test payments, no real money.'
          : 'Your real Razorpay account. Used only after you switch to live payments above.'}
      </p>
      {account && !replacing ? (
        <>
          <p>
            Key ID <span className="mono">{account.key_id}</span>
          </p>
          {fromEnv ? (
            <p className="hint">
              These keys come from the server’s settings (.env or config.php), so they survive a
              database reset. Save other keys here to use those instead.
            </p>
          ) : (
            r.env_key_id?.startsWith(`rzp_${mode}_`) && (
              <p className="hint">
                Saved here, so they’re used instead of the keys in the server’s settings (
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
          <WebhookSetup url={r.webhook_url} secret={freshSecret} mode={mode} />
          {!account.has_webhook_secret && !freshSecret && (
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
        <>
          <KeysForm
            mode={mode}
            submitLabel={`Save ${mode} keys`}
            pending={save.isPending}
            error={save.error}
            onSave={(keys) => save.mutate(keys, { onSuccess: () => setReplacing(false) })}
          />
          {replacing && (
            <Button variant="ghost" onClick={() => setReplacing(false)}>
              Cancel
            </Button>
          )}
        </>
      )}
    </section>
  );
}
