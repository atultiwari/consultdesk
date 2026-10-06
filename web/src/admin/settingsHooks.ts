// Queries and mutations for the Phase 6b screens: users, branding, account, integrations, system.

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError } from '../api/client';
import { adminFetch } from './api';
import { adminKeys } from './hooks';
import type {
  AdminProvider,
  AdminSession,
  Branding,
  GoogleCalendarOption,
  Integrations,
  ManagedUser,
  SystemSnapshot,
} from './types';

export const settingsKeys = {
  users: ['admin', 'users'] as const,
  branding: ['admin', 'branding'] as const,
  system: ['admin', 'system'] as const,
  myTelegram: ['admin', 'my-telegram'] as const,
  integrations: (providerId: number) => ['admin', 'integrations', providerId] as const,
  calendars: (providerId: number) => ['admin', 'calendars', providerId] as const,
};

const once = (count: number, error: Error) =>
  count < 1 && !(error instanceof ApiError && error.status >= 400 && error.status < 500);

function upload(file: File): FormData {
  const form = new FormData();
  form.append('file', file);
  return form;
}

export function useUsers() {
  return useQuery({
    queryKey: settingsKeys.users,
    queryFn: ({ signal }) => adminFetch<ManagedUser[]>('/users', { signal }),
    retry: once,
  });
}

export function useInviteUser() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      adminFetch<ManagedUser>('/users', { method: 'POST', json: body }),
    onSuccess: () => client.invalidateQueries({ queryKey: settingsKeys.users }),
  });
}

export function useUpdateUser() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: number; body: Record<string, unknown> }) =>
      adminFetch<ManagedUser>(`/users/${id}`, { method: 'PATCH', json: body }),
    onSuccess: (updated) =>
      client.setQueryData<ManagedUser[]>(settingsKeys.users, (list = []) =>
        list.map((u) => (u.id === updated.id ? updated : u)),
      ),
  });
}

export function useResendInvite() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => adminFetch<ManagedUser>(`/users/${id}/invite`, { method: 'POST' }),
    onSuccess: () => client.invalidateQueries({ queryKey: settingsKeys.users }),
  });
}

export function useBranding() {
  return useQuery({
    queryKey: settingsKeys.branding,
    queryFn: ({ signal }) => adminFetch<Branding>('/branding', { signal }),
    retry: once,
  });
}

/** Branding changes show on the public site too, so its settings are refreshed. */
function useBrandingMutation<T>(run: (input: T) => Promise<Branding>) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: run,
    onSuccess: async (saved) => {
      client.setQueryData(settingsKeys.branding, saved);
      await client.invalidateQueries({ queryKey: ['site'] });
    },
  });
}

export function useSaveBranding() {
  return useBrandingMutation((body: Record<string, unknown>) =>
    adminFetch<Branding>('/branding', { method: 'PUT', json: body }),
  );
}

export function useUploadLogo() {
  return useBrandingMutation((file: File) =>
    adminFetch<Branding>('/branding/logo', { method: 'POST', form: upload(file) }),
  );
}

export function useRemoveLogo() {
  return useBrandingMutation(() => adminFetch<Branding>('/branding/logo', { method: 'DELETE' }));
}

export function usePhoto(providerId: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (file: File | null) =>
      adminFetch<AdminProvider>(
        `/providers/${providerId}/photo`,
        file ? { method: 'POST', form: upload(file) } : { method: 'DELETE' },
      ),
    onSuccess: async (updated) => {
      client.setQueryData<AdminProvider[]>(adminKeys.providers, (list = []) =>
        list.map((p) => (p.id === updated.id ? updated : p)),
      );
      // The old file is gone, so the public pages in this tab must not keep showing it.
      await Promise.all([
        client.invalidateQueries({ queryKey: ['providers'] }),
        client.invalidateQueries({ queryKey: ['provider'] }),
      ]);
    },
  });
}

export function useRenameMe() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (name: string | null) =>
      adminFetch<AdminSession>('/me', { method: 'PATCH', json: { name } }),
    onSuccess: (session) => client.setQueryData(adminKeys.me, session),
  });
}

export function useChangePassword() {
  return useMutation({
    mutationFn: (body: { current_password: string; new_password: string }) =>
      adminFetch<{ ok: true }>('/me/password', { method: 'POST', json: body }),
  });
}

export function useMyTelegram() {
  return useQuery({
    queryKey: settingsKeys.myTelegram,
    queryFn: ({ signal }) =>
      adminFetch<{ configured: boolean; linked: boolean }>('/me/telegram', { signal }),
    retry: once,
  });
}

/** A one-time "open in Telegram" link, for a provider's chat or (with no id) your own. */
export function useTelegramLink(providerId: number | null) {
  return useMutation({
    mutationFn: () =>
      adminFetch<{ url: string }>(
        providerId === null ? '/me/telegram/link' : `/providers/${providerId}/telegram/link`,
        {
          method: 'POST',
        },
      ),
  });
}

export function useTelegramUnlink(providerId: number | null) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () =>
      adminFetch<{ ok: true }>(
        providerId === null ? '/me/telegram' : `/providers/${providerId}/telegram`,
        { method: 'DELETE' },
      ),
    onSuccess: () =>
      client.invalidateQueries({
        queryKey:
          providerId === null ? settingsKeys.myTelegram : settingsKeys.integrations(providerId),
      }),
  });
}

export function useIntegrations(providerId: number) {
  return useQuery({
    queryKey: settingsKeys.integrations(providerId),
    queryFn: ({ signal }) =>
      adminFetch<Integrations>(`/providers/${providerId}/integrations`, { signal }),
    retry: once,
  });
}

export function useGoogleConnect(providerId: number) {
  return useMutation({
    mutationFn: (body: { email: string; replace: boolean }) =>
      adminFetch<{ url: string }>(`/providers/${providerId}/google/connect`, {
        method: 'POST',
        json: body,
      }),
  });
}

export function useGoogleCalendars(providerId: number, enabled: boolean) {
  return useQuery({
    queryKey: settingsKeys.calendars(providerId),
    queryFn: ({ signal }) =>
      adminFetch<GoogleCalendarOption[]>(`/providers/${providerId}/google/calendars`, { signal }),
    enabled,
    retry: once,
  });
}

export function useSaveCalendars(providerId: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (body: { busy: string[]; target: string }) =>
      adminFetch<Integrations>(`/providers/${providerId}/google/calendars`, {
        method: 'PUT',
        json: body,
      }),
    onSuccess: () => client.invalidateQueries({ queryKey: settingsKeys.integrations(providerId) }),
  });
}

export function useGoogleDisconnect(providerId: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () =>
      adminFetch<Integrations>(`/providers/${providerId}/google`, { method: 'DELETE' }),
    onSuccess: () => client.invalidateQueries({ queryKey: settingsKeys.integrations(providerId) }),
  });
}

export function useSystem() {
  return useQuery({
    queryKey: settingsKeys.system,
    queryFn: ({ signal }) => adminFetch<SystemSnapshot>('/system', { signal }),
    retry: once,
    refetchInterval: 60_000,
  });
}

export function useSystemAction<T>(path: '/system/migrate' | '/system/retry-failed') {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () => adminFetch<T>(path, { method: 'POST' }),
    onSuccess: () => client.invalidateQueries({ queryKey: settingsKeys.system }),
  });
}
