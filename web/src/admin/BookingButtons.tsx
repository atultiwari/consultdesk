import { useState } from 'react';
import { Button } from '../design/components/Button';
import { Notice } from '../design/components/Notice';
import { useBookingAction } from './hooks';
import type { BookingAction } from './types';

const LABEL: Record<BookingAction, string> = {
  confirm: 'Confirm',
  reject: 'Reject',
  cancel: 'Cancel booking',
  complete: 'Mark completed',
  'no-show': 'Mark no-show',
};

/** Actions that tell the customer bad news, or cannot be undone, are asked twice. */
const ASK_FIRST: Partial<Record<BookingAction, { question: string; yes: string }>> = {
  reject: { question: 'Reject this booking? The customer will be emailed.', yes: 'Yes, reject' },
  cancel: { question: 'Cancel this booking? The customer will be emailed.', yes: 'Yes, cancel it' },
  'no-show': { question: 'Mark that the customer did not turn up?', yes: 'Yes, mark no-show' },
};

type Props = {
  bookingId: number;
  actions: BookingAction[];
  /** Short reference for accessible names in lists, e.g. "Confirm CD-7F3K". */
  refForLabels?: string;
  compact?: boolean;
};

export function BookingButtons({ bookingId, actions, refForLabels, compact }: Props) {
  const mutation = useBookingAction();
  const [asking, setAsking] = useState<BookingAction | null>(null);
  const run = (action: BookingAction) => {
    setAsking(null);
    mutation.mutate({ id: bookingId, action });
  };
  const named = (action: BookingAction) =>
    refForLabels ? `${LABEL[action]} ${refForLabels}` : undefined;
  const question = asking ? ASK_FIRST[asking] : undefined;

  if (actions.length === 0) return null;

  return (
    <div className={compact ? 'booking-buttons booking-buttons--compact' : 'booking-buttons'}>
      {mutation.isError && (
        <Notice tone="danger" live>
          {mutation.error.message}
        </Notice>
      )}
      {asking && question ? (
        <div className="booking-buttons__ask" role="group" aria-label="Please confirm">
          <p>{question.question}</p>
          <div className="booking-buttons__row">
            <Button
              className="btn--danger"
              onClick={() => run(asking)}
              disabled={mutation.isPending}
            >
              {question.yes}
            </Button>
            <Button variant="secondary" onClick={() => setAsking(null)}>
              Keep it
            </Button>
          </div>
        </div>
      ) : (
        <div className="booking-buttons__row">
          {actions.map((action) => (
            <Button
              key={action}
              variant={action === 'confirm' || action === 'complete' ? 'primary' : 'secondary'}
              aria-label={named(action)}
              disabled={mutation.isPending}
              onClick={() => (ASK_FIRST[action] ? setAsking(action) : run(action))}
            >
              {LABEL[action]}
            </Button>
          ))}
        </div>
      )}
    </div>
  );
}
