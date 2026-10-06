import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError } from '../api/client';
import { adminFetch } from './api';
import type { SetupState } from './types';

const KEY = ['admin', 'setup'] as const;

const once = (count: number, error: Error) =>
  count < 1 && !(error instanceof ApiError && error.status >= 400 && error.status < 500);

/** The owner's setup state; other roles can't read it, so it is only asked for owners. */
export function useSetup(enabled = true) {
  return useQuery({
    queryKey: KEY,
    queryFn: ({ signal }) => adminFetch<SetupState>('/setup', { signal }),
    enabled,
    retry: once,
    staleTime: 60_000,
  });
}

function useSetupMutation<T>(run: (input: T) => Promise<SetupState>) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: run,
    onSuccess: async (state) => {
      client.setQueryData(KEY, state);
      // Mode and teachers change what the public site and the admin lists show.
      await Promise.all(
        [['site'], ['admin', 'providers'], ['admin', 'services']].map((queryKey) =>
          client.invalidateQueries({ queryKey }),
        ),
      );
    },
  });
}

export const useSetMode = () =>
  useSetupMutation((mode: 'single' | 'multi') =>
    adminFetch<SetupState>('/setup/mode', { method: 'PUT', json: { mode } }),
  );

export const useSaveTeacher = () =>
  useSetupMutation((teacher: Record<string, unknown>) =>
    adminFetch<SetupState>('/setup/teacher', { method: 'POST', json: teacher }),
  );

export const useAddStarterSessions = () =>
  useSetupMutation(
    (sessions: { key: string; title: string; duration_min: number; price_minor: number }[]) =>
      adminFetch<SetupState>('/setup/sessions', { method: 'POST', json: { sessions } }),
  );

export const useCompleteSetup = () =>
  useSetupMutation(() => adminFetch<SetupState>('/setup/complete', { method: 'POST' }));
