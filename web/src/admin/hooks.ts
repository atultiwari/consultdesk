import { QueryClient, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router';
import { ApiError, apiFetch } from '../api/client';
import { adminFetch, adminRequest, rememberCsrf } from './api';
import type {
  AdminEntry,
  AdminProvider,
  AdminService,
  AdminSession,
  BlockedTime,
  BookingAction,
  BookingDetail,
  BookingRow,
  Dashboard,
  HoursWindow,
} from './types';

const DASHBOARD_POLL_MS = 60_000;

export const adminKeys = {
  all: ['admin'] as const,
  me: ['admin', 'me'] as const,
  dashboard: (tz: string) => ['admin', 'dashboard', tz] as const,
  bookings: (query: string) => ['admin', 'bookings', query] as const,
  booking: (id: number) => ['admin', 'booking', id] as const,
  providers: ['admin', 'providers'] as const,
  services: (providerId: number) => ['admin', 'services', providerId] as const,
  hours: (providerId: number) => ['admin', 'hours', providerId] as const,
  blocked: ['admin', 'blocked'] as const,
};

const noRetryOnClientError = (count: number, error: Error) =>
  count < 1 && !(error instanceof ApiError && error.status >= 400 && error.status < 500);

export function isUnauthenticated(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401;
}

/** Drops everything cached for the signed-in person except who they are. */
function forgetAdminData(client: QueryClient): void {
  client.removeQueries({ queryKey: adminKeys.all, predicate: (q) => q.queryKey[1] !== 'me' });
}

/** The session is gone: forget its data and show sign-in (a reset query has no data to fall back on). */
export async function signedOut(client: QueryClient): Promise<void> {
  rememberCsrf('');
  forgetAdminData(client);
  await client.resetQueries({ queryKey: adminKeys.me });
}

/** Whether the secret path is the admin path. Answers 404 otherwise. */
export function useAdminEntry(segment: string, enabled: boolean) {
  return useQuery({
    queryKey: ['admin-entry', segment],
    queryFn: ({ signal }) => apiFetch<AdminEntry>(`/admin/entry/${segment}`, { signal }),
    enabled,
    retry: noRetryOnClientError,
    staleTime: Infinity,
  });
}

export function useMe() {
  return useQuery({
    queryKey: adminKeys.me,
    queryFn: async ({ signal }) => {
      const session = await adminFetch<AdminSession>('/me', { signal });
      rememberCsrf(session.csrf_token);
      return session;
    },
    retry: noRetryOnClientError,
    staleTime: 5 * 60_000,
  });
}

export function useLogin(segment: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) =>
      apiFetch<AdminSession>('/admin/login', {
        method: 'POST',
        json: { path: segment, ...credentials },
      }),
    onSuccess: (session) => {
      rememberCsrf(session.csrf_token);
      forgetAdminData(client);
      client.setQueryData(adminKeys.me, session);
    },
  });
}

export type FirstOwner = { name: string; email: string; password: string; setup_key?: string };

/**
 * Creates the owner on a site with no accounts yet; the new owner is signed in and taken to
 * "Set up your site". (Navigating here, not in the form: the form unmounts once signed in.)
 */
export function useFirstRun(segment: string) {
  const client = useQueryClient();
  const navigate = useNavigate();
  return useMutation({
    mutationFn: (owner: FirstOwner) =>
      apiFetch<AdminSession>('/admin/first-run', {
        method: 'POST',
        json: { path: segment, ...owner },
      }),
    onSuccess: async (session) => {
      rememberCsrf(session.csrf_token);
      forgetAdminData(client);
      client.setQueryData(adminKeys.me, session);
      await navigate(`/${segment}/setup`);
      await client.invalidateQueries({ queryKey: ['admin-entry', segment] });
    },
  });
}

export function useLogout() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (everywhere: boolean) =>
      adminFetch<{ ok: true }>(everywhere ? '/logout-all' : '/logout', { method: 'POST' }),
    onSettled: () => signedOut(client),
  });
}

export function useForgotPassword(segment: string) {
  return useMutation({
    mutationFn: (email: string) =>
      apiFetch<{ ok: true }>('/admin/password/forgot', {
        method: 'POST',
        json: { path: segment, email },
      }),
  });
}

export function useResetPassword(segment: string) {
  return useMutation({
    mutationFn: (body: { token: string; password: string }) =>
      apiFetch<{ ok: true }>('/admin/password/reset', {
        method: 'POST',
        json: { path: segment, ...body },
      }),
  });
}

export function useDashboard(timezone: string) {
  return useQuery({
    queryKey: adminKeys.dashboard(timezone),
    queryFn: ({ signal }) =>
      adminFetch<Dashboard>(`/dashboard?tz=${encodeURIComponent(timezone)}`, { signal }),
    refetchInterval: DASHBOARD_POLL_MS,
    retry: noRetryOnClientError,
  });
}

/** @param query a ready-made query string, e.g. "status=held&page=1&per_page=25" */
export function useBookingList(query: string) {
  return useQuery({
    queryKey: adminKeys.bookings(query),
    queryFn: ({ signal }) => adminRequest<BookingRow[]>(`/bookings?${query}`, { signal }),
    placeholderData: (previous) => previous,
    retry: noRetryOnClientError,
  });
}

export function useBookingDetail(id: number) {
  return useQuery({
    queryKey: adminKeys.booking(id),
    queryFn: ({ signal }) => adminFetch<BookingDetail>(`/bookings/${id}`, { signal }),
    retry: noRetryOnClientError,
  });
}

export function useBookingAction() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, action }: { id: number; action: BookingAction }) =>
      adminFetch<BookingDetail>(`/bookings/${id}/${action}`, { method: 'POST' }),
    onSuccess: (booking) => client.setQueryData(adminKeys.booking(booking.id), booking),
    // Refresh even after a failure: someone else may have settled the booking first.
    onSettled: (_data, _error, { id }) =>
      Promise.all([
        client.invalidateQueries({ queryKey: adminKeys.booking(id) }),
        client.invalidateQueries({ queryKey: ['admin', 'dashboard'] }),
        client.invalidateQueries({ queryKey: ['admin', 'bookings'] }),
      ]),
  });
}

export function useAdminProviders(enabled = true) {
  return useQuery({
    queryKey: adminKeys.providers,
    queryFn: ({ signal }) => adminFetch<AdminProvider[]>('/providers', { signal }),
    retry: noRetryOnClientError,
    enabled,
  });
}

export function useCreateProvider() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      adminFetch<AdminProvider>('/providers', { method: 'POST', json: body }),
    onSuccess: (created) =>
      client.setQueryData<AdminProvider[]>(adminKeys.providers, (list = []) => [...list, created]),
  });
}

export function useUpdateProvider(id: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      adminFetch<AdminProvider>(`/providers/${id}`, { method: 'PATCH', json: body }),
    onSuccess: async (updated) => {
      client.setQueryData<AdminProvider[]>(adminKeys.providers, (list = []) =>
        list.map((p) => (p.id === updated.id ? updated : p)),
      );
      // Names and timezones show up elsewhere too, including the public pages in this tab.
      await Promise.all(
        [
          ['admin', 'blocked'],
          ['admin', 'bookings'],
          ['admin', 'dashboard'],
          ['providers'],
          ['provider'],
        ].map((queryKey) => client.invalidateQueries({ queryKey })),
      );
    },
  });
}

export function useServices(providerId: number) {
  return useQuery({
    queryKey: adminKeys.services(providerId),
    queryFn: ({ signal }) =>
      adminFetch<AdminService[]>(`/providers/${providerId}/services`, { signal }),
    retry: noRetryOnClientError,
  });
}

export function useSaveService(providerId: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ id, body }: { id: number | null; body: Record<string, unknown> }) =>
      id === null
        ? adminFetch<AdminService>(`/providers/${providerId}/services`, {
            method: 'POST',
            json: body,
          })
        : adminFetch<AdminService>(`/services/${id}`, { method: 'PATCH', json: body }),
    onSuccess: (saved) =>
      client.setQueryData<AdminService[]>(adminKeys.services(providerId), (list = []) =>
        list.some((s) => s.id === saved.id)
          ? list.map((s) => (s.id === saved.id ? saved : s))
          : [...list, saved],
      ),
  });
}

export function useHours(providerId: number) {
  return useQuery({
    queryKey: adminKeys.hours(providerId),
    queryFn: ({ signal }) =>
      adminFetch<HoursWindow[]>(`/providers/${providerId}/availability`, { signal }),
    retry: noRetryOnClientError,
  });
}

export function useSaveHours(providerId: number) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (rules: HoursWindow[]) =>
      adminFetch<HoursWindow[]>(`/providers/${providerId}/availability`, {
        method: 'PUT',
        json: { rules },
      }),
    onSuccess: (saved) => client.setQueryData(adminKeys.hours(providerId), saved),
  });
}

export function useBlocked() {
  return useQuery({
    queryKey: adminKeys.blocked,
    queryFn: ({ signal }) => adminFetch<BlockedTime[]>('/blocked', { signal }),
    retry: noRetryOnClientError,
  });
}

export function useCreateBlocked() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (body: Record<string, unknown>) =>
      adminFetch<BlockedTime>('/blocked', { method: 'POST', json: body }),
    onSuccess: () => client.invalidateQueries({ queryKey: adminKeys.blocked }),
  });
}

export function useDeleteBlocked() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => adminFetch<null>(`/blocked/${id}`, { method: 'DELETE' }),
    onSuccess: () => client.invalidateQueries({ queryKey: adminKeys.blocked }),
  });
}
