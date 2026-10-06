export type Remaining = {
  expired: boolean;
  urgent: boolean;
  minutes: number;
  seconds: number;
  label: string;
};

/** Under this many seconds the payment step warns not to start a new payment. */
export const URGENT_SECONDS = 10 * 60;

export function remaining(expiresAt: string, now: Date): Remaining {
  const total = Math.max(0, Math.floor((new Date(expiresAt).getTime() - now.getTime()) / 1000));
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const seconds = total % 60;
  const pad = (n: number) => String(n).padStart(2, '0');

  return {
    expired: total === 0,
    urgent: total > 0 && total < URGENT_SECONDS,
    minutes: hours * 60 + minutes,
    seconds,
    label: hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${minutes}:${pad(seconds)}`,
  };
}
