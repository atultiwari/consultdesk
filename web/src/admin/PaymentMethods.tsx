import { useState } from 'react';
import { Button } from '../design/components/Button';
import { Notice } from '../design/components/Notice';
import { useSaveMethods } from './paymentHooks';
import type { PaymentSettings } from './types';

/** The owner's on/off switches for each way to pay. */
export function PaymentMethods({ initial }: { initial: PaymentSettings['methods'] }) {
  const save = useSaveMethods();
  const [upi, setUpi] = useState(initial.upi_enabled);
  const [razorpay, setRazorpay] = useState(initial.razorpay_enabled);
  const touch = (set: (v: boolean) => void) => (v: boolean) => {
    save.reset();
    set(v);
  };

  return (
    <section className="panel stack" aria-labelledby="methods-h">
      <h2 id="methods-h">Ways to pay</h2>
      <label className="check">
        <input type="checkbox" checked={upi} onChange={(e) => touch(setUpi)(e.target.checked)} />
        <span>UPI to the teacher's UPI ID (the customer enters the UTR; staff verify it)</span>
      </label>
      <label className="check">
        <input
          type="checkbox"
          checked={razorpay}
          onChange={(e) => touch(setRazorpay)(e.target.checked)}
        />
        <span>
          Online with Razorpay (card, UPI app, netbanking, wallet; confirms automatically)
        </span>
      </label>
      <p className="hint">
        A paid session offers the ways switched on here that are also ticked on the session and set
        up for its teacher. Switching one off stops new bookings using it; existing bookings are not
        affected.
      </p>
      {!upi && !razorpay && (
        <Notice tone="warn">With both off, paid sessions can't be booked.</Notice>
      )}
      <div className="form-actions">
        <Button
          onClick={() => save.mutate({ upi_enabled: upi, razorpay_enabled: razorpay })}
          disabled={save.isPending}
        >
          Save ways to pay
        </Button>
        {save.isSuccess && (
          <span className="saved" role="status">
            Saved.
          </span>
        )}
      </div>
    </section>
  );
}
