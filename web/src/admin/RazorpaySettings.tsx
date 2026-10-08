import { useState } from 'react';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Badge, Notice } from '../design/components/Notice';
import { useAdminProviders } from './hooks';
import { useOfferEverywhere, useRemoveProviderKeys, useSaveProviderKeys } from './paymentHooks';
import { KeySet, KeysForm, WebhookSetup } from './RazorpayKeySet';
import type { PaymentSettings } from './types';

export function OrgRazorpay({ settings }: { settings: PaymentSettings }) {
  const offer = useOfferEverywhere();

  return (
    <section className="panel stack" aria-labelledby="rzp-h">
      <h2 id="rzp-h">Razorpay (organisation account)</h2>
      <p className="hint">
        Online payments go to this account unless a teacher has their own below. Each booking gets
        its own payment link for the exact amount. Keep test and live keys here side by side; the
        switch above picks which are used.
      </p>
      <div className="key-sets">
        <KeySet mode="test" settings={settings} />
        <KeySet mode="live" settings={settings} />
      </div>
      {settings.razorpay.configured && (
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
          {offer.isError && (
            <Notice tone="danger" live>
              {offer.error.message}
            </Notice>
          )}
        </div>
      )}
    </section>
  );
}

export function TeacherAccounts({ settings }: { settings: PaymentSettings }) {
  const providers = useAdminProviders();
  const save = useSaveProviderKeys();
  const remove = useRemoveProviderKeys();
  const [providerId, setProviderId] = useState('');

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
            <li key={`${o.provider_id}-${o.mode}`} className="rows__item">
              <span className="rows__main">
                <span className="cell-main">
                  {o.provider_name}{' '}
                  <Badge tone={o.mode === 'live' ? 'success' : 'warn'}>
                    {o.mode === 'live' ? 'Live' : 'Test'}
                  </Badge>
                </span>
                <span className="cell-sub mono">{o.key_id}</span>
                {o.mode !== settings.razorpay.mode && (
                  <span className="cell-sub">
                    Not used in {settings.razorpay.live ? 'live' : 'test'} mode: their sessions are
                    paid into the organisation’s account.
                  </span>
                )}
              </span>
              <Button
                variant="ghost"
                aria-label={`Stop using ${o.provider_name}'s own ${o.mode} account`}
                onClick={() => remove.mutate({ providerId: o.provider_id, mode: o.mode })}
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
          {providers.data?.map((p) => (
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
