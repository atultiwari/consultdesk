import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { fail, mockApi, ok, renderAt } from '../support';
import { ADMIN, signedIn } from './fixtures';

afterEach(() => vi.unstubAllGlobals());

const none = {
  configured: false,
  source: null,
  bot_username: null,
  webhook_url: 'https://book.example.test/api/webhooks/telegram',
  webhook_supported: true,
};
const connected = { ...none, configured: true, source: 'settings', bot_username: 'DemoAlertsBot' };

describe('integrations: Telegram bot', () => {
  it('connects a bot from its token', async () => {
    // A throwaway token-shaped value made per run, so no token-like literal sits in the code.
    const token = `123456789:${crypto.randomUUID().replaceAll('-', '')}`;
    let attempt = 0;
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/integrations/telegram': ok(none),
      'PUT /api/admin/integrations/telegram': () =>
        attempt++ === 0
          ? fail(422, 'validation_failed', 'Check the highlighted fields.', {
              bot_token: 'Telegram didn’t accept this token.',
            })
          : ok(connected),
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/integrations`);

    const section = await screen.findByRole('region', { name: 'Telegram bot' });
    expect(await within(section).findByText('@BotFather')).toBeInTheDocument();
    await user.type(within(section).getByLabelText('Bot token'), token);
    await user.click(within(section).getByRole('button', { name: 'Connect bot' }));
    expect(await within(section).findByText(/didn’t accept this token/)).toBeInTheDocument();

    await user.click(within(section).getByRole('button', { name: 'Connect bot' }));
    expect(await within(section).findByText('@DemoAlertsBot')).toBeInTheDocument();
    expect(within(section).getByRole('link', { name: /My account/ })).toHaveAttribute(
      'href',
      `${ADMIN}/account`,
    );
    expect(calls.find((c) => c.method === 'PUT')?.body).toEqual({ bot_token: token });
  });

  it('reconnects the webhook and removes the bot', async () => {
    let state: object = connected;
    const calls = mockApi({
      ...signedIn(),
      'GET /api/admin/integrations/telegram': () => ok(state),
      'POST /api/admin/integrations/telegram/webhook': ok(connected),
      'DELETE /api/admin/integrations/telegram': () => {
        state = none;
        return ok(none);
      },
    });
    const user = userEvent.setup();
    renderAt(`${ADMIN}/integrations`);

    const section = await screen.findByRole('region', { name: 'Telegram bot' });
    await user.click(await within(section).findByRole('button', { name: 'Reconnect webhook' }));
    expect(await within(section).findByText(/Webhook connected/)).toBeInTheDocument();

    await user.click(within(section).getByRole('button', { name: 'Remove bot' }));
    await user.click(within(section).getByRole('button', { name: 'Yes, remove the bot' }));
    expect(await within(section).findByLabelText('Bot token')).toBeInTheDocument();
    expect(calls.map((c) => `${c.method} ${c.path}`)).toContain(
      'DELETE /api/admin/integrations/telegram',
    );
  });

  it('says when the bot is set in config.php', async () => {
    mockApi({
      ...signedIn(),
      'GET /api/admin/integrations/telegram': ok({
        ...connected,
        source: 'config',
        bot_username: 'ConfigBot',
      }),
    });
    renderAt(`${ADMIN}/integrations`);

    expect(await screen.findByText(/set in the server’s config.php/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Remove bot' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Reconnect webhook' })).toBeInTheDocument();
  });
});
