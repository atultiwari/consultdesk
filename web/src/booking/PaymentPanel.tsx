import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useState } from 'react';
import { keys } from '../api/hooks';
import type { BookingView, UpiPayment } from '../api/types';
import { Button, ButtonAnchor, ButtonLink } from '../design/components/Button';
import { Notice } from '../design/components/Notice';
import { safeHttpsUrl, safeUpiUri } from '../lib/safeUrl';
import { Qr } from './Qr';
import { useRemaining } from './useRemaining';
import { UtrForm } from './UtrForm';

function CopyButton({ value, label }: { value: string; label: string }) {
  const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');
  const copy = () => {
    const done = (next: 'copied' | 'failed') => {
      setState(next);
      window.setTimeout(() => setState('idle'), 2500);
    };
    if (!navigator.clipboard) return done('failed');
    navigator.clipboard.writeText(value).then(
      () => done('copied'),
      () => done('failed'),
    );
  };

  return (
    <Button variant="ghost" onClick={copy} aria-label={`Copy ${label}`}>
      {state === 'copied' ? 'Copied ✓' : state === 'failed' ? 'Copy it by hand' : 'Copy'}
    </Button>
  );
}

function UpiDetails({ payment }: { payment: UpiPayment }) {
  const uri = safeUpiUri(payment.upi_uri);
  if (!payment.available || !uri || !payment.vpa) {
    return (
      <Notice tone="warn" title="UPI details are unavailable right now">
        Please contact the provider before paying.
      </Notice>
    );
  }

  return (
    <div className="upi">
      <div className="upi__pay">
        <ButtonAnchor href={uri} large block className="upi__app-button">
          Pay {payment.amount_display} in a UPI app
        </ButtonAnchor>
        <div className="upi__qr">
          <Qr
            value={uri}
            label={`UPI QR code to pay ${payment.amount_display} to ${payment.payee_name}`}
          />
          <p className="muted">Scan with any UPI app</p>
        </div>
      </div>
      <dl className="upi__rows">
        <div>
          <dt>UPI ID</dt>
          <dd>
            <span className="mono">{payment.vpa}</span>{' '}
            <CopyButton value={payment.vpa} label="UPI ID" />
          </dd>
        </div>
        <div>
          <dt>Payee</dt>
          <dd>{payment.payee_name}</dd>
        </div>
        <div>
          <dt>Amount</dt>
          <dd className="mono">{payment.amount_display}</dd>
        </div>
      </dl>
    </div>
  );
}

export function PaymentPanel({ booking, token }: { booking: BookingView; token: string }) {
  const client = useQueryClient();
  const refresh = useCallback(
    () => void client.invalidateQueries({ queryKey: keys.booking(booking.ref) }),
    [client, booking.ref],
  );
  const left = useRemaining(booking.hold_expires_at, refresh);
  const payment = booking.payment;
  if (!payment) return null;
  const whatsapp = safeHttpsUrl(payment.whatsapp_url);

  if (!payment.can_submit_utr) {
    return (
      <section className="pay card" aria-labelledby="pay-heading">
        <h2 id="pay-heading" className="pay__title">
          Payment received — we’re checking it
        </h2>
        <p>
          UTR <span className="mono">{booking.utr}</span> is with {booking.provider.name} for
          verification. You’ll get an email as soon as your session is confirmed.
        </p>
        {whatsapp && (
          <ButtonAnchor
            variant="secondary"
            href={whatsapp}
            target="_blank"
            rel="noopener noreferrer"
          >
            Send the payment screenshot on WhatsApp
          </ButtonAnchor>
        )}
      </section>
    );
  }

  // The hold ran out on this device's (server-corrected) clock: stop offering payment right away,
  // without waiting for the server to mark it expired.
  if (left?.expired) {
    return (
      <section className="pay card" aria-labelledby="pay-heading">
        <h2 id="pay-heading" className="pay__title">
          This hold has run out
        </h2>
        <Notice tone="danger" title="Do not pay for this booking" live>
          The time is no longer reserved. If you already paid just now, contact{' '}
          {booking.provider.name} with booking {booking.ref}.
        </Notice>
        <ButtonLink to={`/p/${booking.provider.slug}`}>Book a new time</ButtonLink>
      </section>
    );
  }

  return (
    <section className="pay card" aria-labelledby="pay-heading">
      <div className="pay__head">
        <h2 id="pay-heading" className="pay__title">
          Pay to confirm
        </h2>
        {left && (
          <p className={`hold${left.urgent ? ' hold--urgent' : ''}`} role="timer" aria-live="off">
            Held for <span className="mono">{left.label}</span>
          </p>
        )}
      </div>

      {left?.urgent && (
        <Notice tone="warn" title="Less than 10 minutes left" live>
          Only start a payment if you can enter its UTR before the timer ends. Otherwise book a new
          time instead.
        </Notice>
      )}

      <UpiDetails payment={payment} />

      <Notice tone="info" title="Pay once, for this booking only">
        Your UTR can confirm only one booking. Enter it straight after paying; if this hold runs out
        first, don’t pay for it — book a new time.
      </Notice>

      <UtrForm
        refCode={booking.ref}
        token={token}
        providerName={booking.provider.name}
        whatsappUrl={whatsapp}
      />

      {whatsapp && (
        <p className="muted pay__wa">
          Prefer to send a screenshot?{' '}
          <a href={whatsapp} target="_blank" rel="noopener noreferrer">
            Send it on WhatsApp
          </a>{' '}
          as well.
        </p>
      )}
    </section>
  );
}
