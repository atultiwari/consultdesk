// The status token is never handed to Razorpay. Before leaving for Razorpay's page it is kept in this
// tab's session storage, so the return page can open the booking again.

const PREFIX = 'consultdesk-pay-';

export function rememberToken(ref: string, token: string): void {
  try {
    sessionStorage.setItem(PREFIX + ref, token);
  } catch {
    // Private mode or storage blocked: the confirmation email still has the link.
  }
}

export function recallToken(ref: string): string | null {
  try {
    return sessionStorage.getItem(PREFIX + ref);
  } catch {
    return null;
  }
}

/** The signed parameters Razorpay adds when it sends the customer back. */
export const RETURN_PARAMS = [
  'razorpay_payment_id',
  'razorpay_payment_link_id',
  'razorpay_payment_link_reference_id',
  'razorpay_payment_link_status',
  'razorpay_signature',
] as const;
