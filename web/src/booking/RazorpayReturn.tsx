import { useEffect, useRef } from 'react';
import { Link, useNavigate } from 'react-router';
import { useRazorpayReturn } from '../api/hooks';
import { Loading, Notice } from '../design/components/Notice';
import { recallToken, RETURN_PARAMS } from './payToken';

/**
 * Razorpay sends the customer back to /b/{ref}?paid=1&razorpay_…. The server checks Razorpay's
 * signature and confirms the booking; then the booking page opens again with the token this tab
 * kept (Razorpay never sees it). In another tab or browser, the email link does the same.
 */
export function RazorpayReturn({ refCode, params }: { refCode: string; params: URLSearchParams }) {
  const confirm = useRazorpayReturn(refCode);
  const navigate = useNavigate();
  const sent = useRef(false);

  useEffect(() => {
    if (sent.current) return;
    sent.current = true;
    const body = Object.fromEntries(RETURN_PARAMS.map((key) => [key, params.get(key) ?? '']));
    confirm.mutate(body, {
      onSettled: () => {
        const token = recallToken(refCode);
        if (token)
          void navigate(`/b/${encodeURIComponent(refCode)}?t=${encodeURIComponent(token)}`, {
            replace: true,
          });
      },
    });
  }, [confirm, navigate, params, refCode]);

  if (confirm.isPending || confirm.isIdle) {
    return (
      <div className="container">
        <Loading label="Checking your payment…" />
      </div>
    );
  }

  const confirmed = confirm.data?.status === 'confirmed';
  return (
    <div className="container status">
      {confirmed ? (
        <Notice tone="success" title="Payment received — you’re booked" live>
          Booking <span className="mono">{refCode}</span> is confirmed. The confirmation email has
          the link to your booking and the call details.
        </Notice>
      ) : (
        <Notice tone="warn" title="We couldn’t confirm the payment here" live>
          If you paid, your booking will confirm itself within a few minutes and you’ll get an
          email. Open the link in your booking email to check.{' '}
          <Link to="/">Back to the booking site</Link>
        </Notice>
      )}
    </div>
  );
}
