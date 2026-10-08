import { Button } from '../design/components/Button';
import { Badge, Notice } from '../design/components/Notice';
import { useTelegramLink, useTelegramUnlink } from './settingsHooks';

type Props = {
  /** null for your own chat */
  providerId: number | null;
  configured: boolean;
  linked: boolean;
};

/** Link or unlink a Telegram chat for alerts with Confirm/Reject buttons. */
export function TelegramBox({ providerId, configured, linked }: Props) {
  const link = useTelegramLink(providerId);
  const unlink = useTelegramUnlink(providerId);

  if (!configured) {
    return (
      <p className="hint">
        No Telegram bot is connected yet. The owner can connect one in Integrations.
      </p>
    );
  }

  return (
    <div className="stack">
      {linked ? (
        <p>
          <Badge tone="success">Linked</Badge> Alerts arrive in Telegram.{' '}
          <Button variant="ghost" onClick={() => unlink.mutate()} disabled={unlink.isPending}>
            Unlink
          </Button>
        </p>
      ) : (
        <p className="hint">
          {providerId === null
            ? 'Get alerts in your own Telegram with buttons to confirm or reject.'
            : 'Send new bookings to this provider’s Telegram, with buttons to confirm or reject.'}
        </p>
      )}
      {link.data ? (
        <Notice tone="info" title="Open this on the phone that should get alerts">
          <p>
            <a className="btn" href={link.data.url} target="_blank" rel="noreferrer">
              Open in Telegram
            </a>
          </p>
          <p className="cell-sub">
            It works once, for {providerId === null ? '15 minutes' : '24 hours'}, and only in a
            private chat.
          </p>
        </Notice>
      ) : (
        <Button variant="secondary" onClick={() => link.mutate()} disabled={link.isPending}>
          {linked ? 'Link another chat' : 'Get a Telegram link'}
        </Button>
      )}
      {(link.isError || unlink.isError) && (
        <Notice tone="danger" live>
          {(link.error ?? unlink.error)?.message}
        </Notice>
      )}
    </div>
  );
}
