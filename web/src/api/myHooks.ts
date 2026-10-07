// "My bookings": the customer's own bookings, signed in with an emailed link.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiFetch } from './client';
import type { MyBookings } from './types';

export const myKeys = { bookings: ['my-bookings'] as const };

export function useMyBookings() {
  return useQuery({
    queryKey: myKeys.bookings,
    queryFn: ({ signal }) => apiFetch<MyBookings>('/my/bookings', { signal }),
    retry: (count, error) => count < 1 && !(error instanceof ApiError && error.status < 500),
  });
}

export function useRequestLink() {
  return useMutation({
    mutationFn: (email: string) =>
      apiFetch<{ ok: true }>('/my/link', { method: 'POST', json: { email } }),
  });
}

export function useSignInWithLink() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (token: string) =>
      apiFetch<{ email: string }>('/my/session', { method: 'POST', json: { token } }),
    onSuccess: () => client.invalidateQueries({ queryKey: myKeys.bookings }),
  });
}

export function useCancelMine() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (ref: string) =>
      apiFetch<MyBookings>(`/my/bookings/${encodeURIComponent(ref)}/cancel`, {
        method: 'POST',
        json: {},
      }),
    onSuccess: (bookings) => client.setQueryData(myKeys.bookings, bookings),
  });
}

export function useSignOutMine() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch<{ ok: true }>('/my/logout', { method: 'POST', json: {} }),
    onSettled: () => client.resetQueries({ queryKey: myKeys.bookings }),
  });
}
