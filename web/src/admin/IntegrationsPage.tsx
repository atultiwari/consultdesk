import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { Button } from '../design/components/Button';
import { Field } from '../design/components/Field';
import { Badge, Loading, Notice } from '../design/components/Notice';
import { useAdmin } from './context';
import { fieldErrors } from './forms';
import {
  useConnectTelegramBot,
  useReconnectTelegramWebhook,
  useRemoveTelegramBot,
  useTelegramBot,
  type TelegramBot,
} from './telegramBotHooks';

function ConnectForm() {
  const connect = useConnectTelegramBot();
  const [token, setToken] = useState('');
  const errors = fieldErrors(connect.error);
  const submit = (event: FormEvent) => {
    event.preventDefault();
    connect.mutate(token.trim(), { onSuccess: () => setToken('') });
  };

  return (
    <form className="stack" onSubmit={submit} noValidate>
      <ol className="steps">
        <li>
          In Telegram, open <strong>@BotFather</strong> and send{' '}
          <span className="mono">/newbot</span>.
        </li>
        <li>Choose a name (e.g. “Bookings alerts”) and a username ending in “bot”.</li>
        <li>BotFather replies with an API token: paste it below.</li>
      </ol>
      <Field
        label="Bot token"
        hint="Looks like 123456789:AAH…. Stored encrypted; nobody can read it back."
        error={errors.bot_token}
      >
        <input
          className="input mono"
          type="password"
          value={token}
          autoComplete="off"
          spellCheck={false}
          onChange={(e) => setToken(e.target.value)}
        />
      </Field>
      {connect.isError && !errors.bot_token && (
        <Notice tone="danger" live>
          {connect.error.message}
        </Notice>
      )}
      <div className="form-actions">
        <Button type="submit" disabled={connect.isPending || token.trim() === ''}>
          {connect.isPending ? 'Connecting…' : 'Connect bot'}
        </Button>
      </div>
    </form>
  );
}

function ConnectedBot({ bot }: { bot: TelegramBot }) {
  const { base } = useAdmin();
  const reconnect = useReconnectTelegramWebhook();
  const remove = useRemoveTelegramBot();
  const [asking, setAsking] = useState(false);
  const fromConfig = bot.source === 'config';
  const problem = [reconnect, remove].find((m) => m.isError)?.error;

  return (
    <div className="stack">
      <p>
        <Badge tone="success">Connected</Badge> <strong>@{bot.bot_username}</strong>
      </p>
      {fromConfig && (
        <p className="hint">
          This bot is set in the server’s config.php, so it’s changed or removed there.
        </p>
      )}
      <p className="hint">
        Next, link the chats that should get alerts: yours in{' '}
        <Link to={`${base}/account`}>My account → Telegram</Link>, and each teacher’s on their
        profile (Connections).
      </p>
      {!bot.webhook_supported && (
        <Notice tone="warn">
          This site isn’t on https, so Telegram can’t reach it. For local testing run{' '}
          <span className="mono">php bin/telegram.php poll</span>.
        </Notice>
      )}
      {reconnect.isSuccess && (
        <Notice tone="success" live>
          Webhook connected: Telegram sends button presses to this site.
        </Notice>
      )}
      {problem && (
        <Notice tone="danger" live>
          {problem.message}
        </Notice>
      )}
      <div className="form-actions">
        <Button
          variant="secondary"
          onClick={() => reconnect.mutate()}
          disabled={reconnect.isPending || !bot.webhook_supported}
        >
          Reconnect webhook
        </Button>
        {fromConfig ? null : asking ? (
          <>
            <Button
              className="btn--danger"
              onClick={() => remove.mutate(undefined, { onSettled: () => setAsking(false) })}
              disabled={remove.isPending}
            >
              Yes, remove the bot
            </Button>
            <Button variant="secondary" onClick={() => setAsking(false)}>
              Keep it
            </Button>
          </>
        ) : (
          <Button variant="ghost" onClick={() => setAsking(true)}>
            Remove bot
          </Button>
        )}
      </div>
      {asking && (
        <p className="hint">
          Alerts stop until a bot is connected again. Linked chats are kept; with a different bot,
          everyone links again.
        </p>
      )}
    </div>
  );
}

/** Services connected to this site: the Telegram bot for booking alerts. */
export function IntegrationsPage() {
  const bot = useTelegramBot();

  return (
    <div className="page">
      <header className="page__head">
        <h1>Integrations</h1>
        <p className="page__sub">Services this site works with.</p>
      </header>
      <section className="panel stack" aria-labelledby="tg-bot-h">
        <h2 id="tg-bot-h">Telegram bot</h2>
        <p className="hint">
          Sends new bookings and payments to verify to Telegram, with buttons to confirm or reject
          them. Optional: emails are sent either way.
        </p>
        {bot.isPending && <Loading />}
        {bot.isError && (
          <Notice tone="danger" live>
            {bot.error.message}
          </Notice>
        )}
        {bot.data && (bot.data.configured ? <ConnectedBot bot={bot.data} /> : <ConnectForm />)}
      </section>
    </div>
  );
}
