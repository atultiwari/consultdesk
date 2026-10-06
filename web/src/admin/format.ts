import type { BookingStatus, BookingRow } from './types';

/** "₹2,999", or "₹2,999.50" when there are paise. */
export function formatMoney(minor: number, currency: string): string {
  return new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency,
    minimumFractionDigits: minor % 100 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  }).format(minor / 100);
}

/** "Wed 7 Oct, 10:00 am" in the given timezone. */
export function formatWhen(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-IN', {
    timeZone,
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(iso));
}

export function formatDate(iso: string, timeZone: string): string {
  return new Intl.DateTimeFormat('en-IN', {
    timeZone,
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(iso));
}

export const STATUS_LABEL: Record<BookingStatus, string> = {
  held: 'Awaiting payment',
  awaiting_verification: 'Payment to verify',
  confirmed: 'Confirmed',
  rejected: 'Rejected',
  expired: 'Expired',
  cancelled: 'Cancelled',
  completed: 'Completed',
  no_show: 'No-show',
  rescheduled: 'Rescheduled',
};

export const STATUS_TONE: Record<
  BookingStatus,
  'brand' | 'accent' | 'success' | 'warn' | 'danger' | undefined
> = {
  held: 'warn',
  awaiting_verification: 'accent',
  confirmed: 'success',
  rejected: 'danger',
  expired: undefined,
  cancelled: undefined,
  completed: 'brand',
  no_show: 'danger',
  rescheduled: undefined,
};

/** A held free booking is a request waiting for approval, not a payment. */
export function statusLabel(booking: Pick<BookingRow, 'status' | 'payment_method'>): string {
  return booking.status === 'held' && booking.payment_method === 'free'
    ? 'Awaiting approval'
    : STATUS_LABEL[booking.status];
}

const HISTORY_LABEL: Record<string, string> = {
  'booking.held': 'Booked, slot held',
  'booking.utr_submitted': 'UTR submitted',
  'booking.confirmed': 'Confirmed',
  'booking.rejected': 'Rejected',
  'booking.cancelled': 'Cancelled',
  'booking.completed': 'Marked completed',
  'booking.no_show': 'Marked no-show',
  'booking.expired': 'Hold expired',
};

export function historyLabel(action: string): string {
  return HISTORY_LABEL[action] ?? action.replace(/^booking\./, '').replace(/_/g, ' ');
}

export const WEEKDAYS = [
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
  'Sunday',
];
