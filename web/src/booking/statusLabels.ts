import type { BookingState } from '../api/types';

/** How each booking state is shown to customers. */
export const STATUS: Record<
  BookingState,
  { label: string; tone: 'brand' | 'accent' | 'success' | 'warn' | 'danger' }
> = {
  held: { label: 'Awaiting payment', tone: 'warn' },
  awaiting_verification: { label: 'Verifying payment', tone: 'brand' },
  confirmed: { label: 'Confirmed', tone: 'success' },
  rejected: { label: 'Not confirmed', tone: 'danger' },
  expired: { label: 'Expired', tone: 'danger' },
  cancelled: { label: 'Cancelled', tone: 'danger' },
  completed: { label: 'Completed', tone: 'success' },
  no_show: { label: 'Missed', tone: 'danger' },
  rescheduled: { label: 'Rescheduled', tone: 'brand' },
};
