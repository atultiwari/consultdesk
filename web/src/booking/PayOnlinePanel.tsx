import { usePayOnline } from '../api/hooks';
import type { BookingView, RazorpayPayment } from '../api/types';
import { Button, ButtonAnchor } from '../design/components/Button';
import { Notice } from '../design/components/Notice';
import type { Remaining } from '../lib/countdown';
import { safeHttpsUrl } from '../lib/safeUrl';
import { rememberToken } from './payToken';

type Props = {
  booking: BookingView;
  payment: RazorpayPayment;
  token: string;
  left: Remaining | null;
};

/** Pay online on Razorpay's page; the booking confirms itself when the payment goes through. */
export function OnlinePayment({ booking, payment, token, left }: Props) {
  const payOnline = usePayOnline(booking.ref);
  const url = safeHttpsUrl(payment.pay_url);

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
      <p>
        Pay {payment.amount_display} on Razorpay's secure page with a card, any UPI app, netbanking
        or a wallet. You'll come straight back here, and your booking is confirmed as soon as the
        payment goes through.
      </p>
      {url ? (
        <ButtonAnchor href={url} large block onClick={() => rememberToken(booking.ref, token)}>
          Pay {payment.amount_display} online
        </ButtonAnchor>
      ) : (
        <>
          <Notice tone="warn" title="The payment page isn't ready yet" live>
            Razorpay didn't answer just now. Try again in a moment.
          </Notice>
          <Button block onClick={() => payOnline.mutate(token)} disabled={payOnline.isPending}>
            {payOnline.isPending ? 'Getting the payment page…' : 'Get the payment page'}
          </Button>
          {payOnline.isError && (
            <Notice tone="danger" live>
              {payOnline.error.message}
            </Notice>
          )}
        </>
      )}
      <p className="muted">
        Pay once. If the timer runs out before you pay, book a new time instead.
      </p>
    </section>
  );
}
