import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminFetch } from './api';

export type ReleaseInfo = {
  version: string;
  notes: string;
  published_at: string | null;
  page_url: string;
  prerelease: boolean;
};

export type UpdateStatus = {
  current: string;
  latest: ReleaseInfo | null;
  available: boolean;
  checked_at: string | null;
  error: string | null;
  last_update: { from: string; to: string; at: string } | null;
  /** Whether "Update now" can work on this installation. */
  can_update: boolean;
  /** Why it can't (e.g. a missing PHP extension), or null. */
  blocker: string | null;
};

export type UpdateResult = { from: string; to: string; backup: string; migrations: string[] };

const KEY = ['admin', 'updates'] as const;

export function useUpdateStatus(enabled = true) {
  return useQuery({
    queryKey: KEY,
    queryFn: ({ signal }) => adminFetch<UpdateStatus>('/system/updates', { signal }),
    enabled,
    staleTime: 10 * 60_000,
  });
}

export function useCheckForUpdates() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () => adminFetch<UpdateStatus>('/system/updates/check', { method: 'POST' }),
    onSuccess: (status) => client.setQueryData(KEY, status),
  });
}

export function useApplyUpdate() {
  return useMutation({
    mutationFn: (password: string) =>
      adminFetch<UpdateResult>('/system/updates/apply', { method: 'POST', json: { password } }),
  });
}
