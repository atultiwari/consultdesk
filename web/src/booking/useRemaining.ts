import { useEffect, useState } from 'react';
import { remaining, type Remaining } from '../lib/countdown';
import { serverNow } from '../lib/serverTime';

/** Ticks every second until the hold lapses, then calls onExpire once. */
export function useRemaining(expiresAt: string | null, onExpire: () => void): Remaining | null {
  const [now, setNow] = useState(serverNow);
  const state = expiresAt ? remaining(expiresAt, now) : null;
  const expired = state?.expired ?? false;

  useEffect(() => {
    if (expiresAt === null) return undefined;
    if (expired) {
      onExpire();
      return undefined;
    }
    const timer = window.setInterval(() => setNow(serverNow()), 1000);
    return () => window.clearInterval(timer);
  }, [expiresAt, expired, onExpire]);

  return state;
}
