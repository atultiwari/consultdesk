import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useState } from 'react';
import { keys } from '../api/hooks';
import type { BookingView, UpiPayment } from '../api/types';
import { Button, ButtonAnchor } from '../design/components/Button';
import { Notice } from '../design/components/Notice';
import { Countdown } from './Countdown';
import { Qr } from './Qr';
import { UtrForm } from './UtrForm';

function CopyButton({ value, label }: { value: string; label: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <Button
      variant="ghost"
      onClick={() => {
        void navigator.clipboard?.writeText(value).then(() => {
          setCopied(true);
          window.setTimeout(() => setCopied(false), 2000);
        });
      }}
      aria-label={`Copy ${label}`}
    >
      {copied ? 'Copied ✓' : 'Copy'}
    </Button>
  );
}

function UpiDetails({ payment }: { payment: UpiPayment }) {
  if (!payment.available || !payment.upi_uri || !payment.vpa) {
    return (
      <Notice tone="warn" title="UPI details are unavailable right now">
        Please contact the provider before paying.
      </Notice>
    );
  }

  return (
    <div className="upi">
      <div className="upi__pay">
        <ButtonAnchor href={payment.upi_uri} large block className="upi__app-button">
          Pay {payment.amount_display} in a UPI app
        </ButtonAnchor>
        <div className="upi__qr">
          <Qr
            value={payment.upi_uri}
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
  const payment = booking.payment;
  if (!payment) return null;

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
        {payment.whatsapp_url && (
          <ButtonAnchor
            variant="secondary"
            href={payment.whatsapp_url}
            target="_blank"
            rel="noopener noreferrer"
          >
            Send the payment screenshot on WhatsApp
          </ButtonAnchor>
        )}
      </section>
    );
  }

  return (
    <section className="pay card" aria-labelledby="pay-heading">
      <div className="pay__head">
        <h2 id="pay-heading" className="pay__title">
          Pay to confirm
        </h2>
        {booking.hold_expires_at && (
          <Countdown expiresAt={booking.hold_expires_at} onExpire={refresh}>
            {(t) => (
              <p
                className={`hold${t.urgent ? ' hold--urgent' : ''}`}
                role="timer"
                aria-live={t.urgent ? 'polite' : 'off'}
              >
                Held for <span className="mono">{t.label}</span>
              </p>
            )}
          </Countdown>
        )}
      </div>

      {booking.hold_expires_at && (
        <Countdown expiresAt={booking.hold_expires_at}>
          {(t) =>
            t.urgent ? (
              <Notice tone="warn" title="Less than 10 minutes left" live>
                Only start a payment if you can enter its UTR before the timer ends. Otherwise book
                a new time instead.
              </Notice>
            ) : null
          }
        </Countdown>
      )}

      <UpiDetails payment={payment} />

      <Notice tone="info" title="Pay once, for this booking only">
        Your UTR can confirm only one booking. Enter it straight after paying; if this hold runs out
        first, don’t pay for it — book a new time.
      </Notice>

      <UtrForm refCode={booking.ref} token={token} />

      {payment.whatsapp_url && (
        <p className="muted pay__wa">
          Prefer to send a screenshot?{' '}
          <a href={payment.whatsapp_url} target="_blank" rel="noopener noreferrer">
            Send it on WhatsApp
          </a>{' '}
          as well.
        </p>
      )}
    </section>
  );
}
