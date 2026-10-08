import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError } from '../api/client';
import { adminFetch } from './api';
import { settingsKeys } from './settingsHooks';

export type TelegramBot = {
  configured: boolean;
  /** "config": set in the server's config.php (managed there); "settings": connected here. */
  source: 'config' | 'settings' | null;
  bot_username: string | null;
  webhook_url: string;
  /** False on a local http site, where Telegram can't deliver webhooks. */
  webhook_supported: boolean;
};

const KEY = ['admin', 'telegram-bot'] as const;

const once = (count: number, error: Error) =>
  count < 1 && !(error instanceof ApiError && error.status >= 400 && error.status < 500);

export function useTelegramBot() {
  return useQuery({
    queryKey: KEY,
    queryFn: ({ signal }) => adminFetch<TelegramBot>('/integrations/telegram', { signal }),
    retry: once,
  });
}

/** Saves the answer, and refreshes everything that shows whether Telegram is set up. */
function useBotMutation<T>(run: (input: T) => Promise<TelegramBot>) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: run,
    onSuccess: async (bot) => {
      client.setQueryData(KEY, bot);
      await Promise.all([
        client.invalidateQueries({ queryKey: settingsKeys.myTelegram }),
        client.invalidateQueries({ queryKey: ['admin', 'integrations'] }),
      ]);
    },
  });
}

export function useConnectTelegramBot() {
  return useBotMutation((botToken: string) =>
    adminFetch<TelegramBot>('/integrations/telegram', {
      method: 'PUT',
      json: { bot_token: botToken },
    }),
  );
}

export function useReconnectTelegramWebhook() {
  return useBotMutation(() =>
    adminFetch<TelegramBot>('/integrations/telegram/webhook', { method: 'POST' }),
  );
}

export function useRemoveTelegramBot() {
  return useBotMutation(() =>
    adminFetch<TelegramBot>('/integrations/telegram', { method: 'DELETE' }),
  );
}
