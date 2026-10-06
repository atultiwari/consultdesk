import { useEffect, useState } from 'react';
import { remaining } from '../lib/countdown';

type Props = {
  expiresAt: string;
  onExpire?: () => void;
  children: (state: ReturnType<typeof remaining>) => React.ReactNode;
};

export function Countdown({ expiresAt, onExpire, children }: Props) {
  const [now, setNow] = useState(() => new Date());
  const state = remaining(expiresAt, now);

  useEffect(() => {
    if (state.expired) {
      onExpire?.();
      return undefined;
    }
    const timer = window.setInterval(() => setNow(new Date()), 1000);
    return () => window.clearInterval(timer);
  }, [state.expired, onExpire]);

  return <>{children(state)}</>;
}
