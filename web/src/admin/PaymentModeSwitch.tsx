import { useState } from 'react';
import { Button } from '../design/components/Button';
import { Notice } from '../design/components/Notice';
import { useSavePaymentMode } from './paymentHooks';
import type { PaymentSettings } from './types';

/**
 * The Test/Live switch with a bar that always says which one customers get. Either way needs a
 * confirmation: live charges real money, and test on a real site means customers can't really pay.
 */
export function PaymentModeSwitch({ settings }: { settings: PaymentSettings }) {
  const save = useSavePaymentMode();
  const [asking, setAsking] = useState(false);
  const r = settings.razorpay;
  const live = r.live;
  const liveKeys = r.accounts.live;
  const canGoLive = live || liveKeys !== null;

  const confirm = () => save.mutate(!live, { onSettled: () => setAsking(false) });

  return (
    <section
      className={`panel stack pay-mode pay-mode--${live ? 'live' : 'test'}`}
      aria-labelledby="pay-mode-h"
    >
      <div className="pay-mode__head">
        <h2 id="pay-mode-h">Test or live payments</h2>
        <label className="switch">
          <button
            type="button"
            role="switch"
            aria-checked={live}
            aria-label="Live payments"
            className="switch__track"
            disabled={!canGoLive || save.isPending}
            onClick={() => setAsking(true)}
          >
            <span className="switch__thumb" aria-hidden="true" />
          </button>
          <span aria-hidden="true">Live payments</span>
        </label>
      </div>
      {live ? (
        <Notice tone="success" title="Live: customers pay real money">
          {r.key_id ? (
            <>
              Online payments go to your live Razorpay account (
              <span className="mono">{r.key_id}</span>).
            </>
          ) : (
            'No live keys are saved, so online payment is off. Add live keys below or switch to test mode.'
          )}
        </Notice>
      ) : (
        <Notice tone="warn" title="Test mode: no real money moves">
          Customers can only pay with Razorpay’s test methods (e.g. UPI{' '}
          <span className="mono">success@razorpay</span>). Use it to try bookings out.
        </Notice>
      )}
      {!canGoLive && (
        <p className="hint">
          Save your live keys below first (Razorpay Dashboard in Live Mode → API Keys); then switch
          this on.
        </p>
      )}
      {asking &&
        (live ? (
          <Notice tone="warn" title="Switch to test mode?">
            <p>
              New bookings get Razorpay test payment links, so real customers can’t pay online until
              you switch back. Links already sent keep working.
            </p>
            <div className="form-actions">
              <Button onClick={confirm} disabled={save.isPending}>
                Yes, switch to test mode
              </Button>
              <Button variant="secondary" onClick={() => setAsking(false)}>
                Stay live
              </Button>
            </div>
          </Notice>
        ) : (
          <Notice tone="danger" title="Take real payments?">
            <p>
              From now on customers are charged real money into the live account{' '}
              <span className="mono">{liveKeys?.key_id}</span>. Make sure its webhook is set up in
              Razorpay (Live Mode) so bookings confirm even if a customer closes the page.
            </p>
            <div className="form-actions">
              <Button className="btn--danger" onClick={confirm} disabled={save.isPending}>
                Yes, take real payments
              </Button>
              <Button variant="secondary" onClick={() => setAsking(false)}>
                Cancel
              </Button>
            </div>
          </Notice>
        ))}
      {save.isError && (
        <Notice tone="danger" live>
          {save.error.message}
        </Notice>
      )}
    </section>
  );
}
