import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiFetch } from './client';
import type {
  BookingView,
  CouponQuote,
  CreatedBooking,
  NewBooking,
  ProviderDetail,
  ProviderProfile,
  RazorpayReturn,
  Site,
  SlotsResponse,
} from './types';

const PENDING_POLL_MS = 30_000;

export const keys = {
  site: ['site'] as const,
  providers: ['providers'] as const,
  provider: (slug: string) => ['provider', slug] as const,
  slots: (provider: string, service: string, from: string, to: string) =>
    ['slots', provider, service, from, to] as const,
  booking: (ref: string) => ['booking', ref] as const,
};

export function useSite() {
  return useQuery({
    queryKey: keys.site,
    queryFn: ({ signal }) => apiFetch<Site>('/site', { signal }),
    staleTime: 5 * 60_000,
  });
}

export function useProviders() {
  return useQuery({
    queryKey: keys.providers,
    queryFn: ({ signal }) => apiFetch<ProviderProfile[]>('/providers', { signal }),
  });
}

export function useProvider(slug: string) {
  return useQuery({
    queryKey: keys.provider(slug),
    queryFn: ({ signal }) =>
      apiFetch<ProviderDetail>(`/providers/${encodeURIComponent(slug)}`, { signal }),
  });
}

export function useSlots(provider: string, service: string, from: string, to: string) {
  return useQuery({
    queryKey: keys.slots(provider, service, from, to),
    queryFn: ({ signal }) =>
      apiFetch<SlotsResponse>(
        `/providers/${encodeURIComponent(provider)}/services/${encodeURIComponent(service)}/slots?from=${from}&to=${to}`,
        { signal },
      ),
    staleTime: 30_000,
    placeholderData: (previous) => previous,
  });
}

export type CouponCheck = { provider: string; service: string; code: string; email?: string };

export function useCheckCoupon() {
  return useMutation({
    mutationFn: (check: CouponCheck) =>
      apiFetch<CouponQuote>('/coupons/check', { method: 'POST', json: check }),
  });
}

export function useCreateBooking() {
  return useMutation({
    mutationFn: (booking: NewBooking) =>
      apiFetch<CreatedBooking>('/bookings', { method: 'POST', json: booking }),
  });
}

const PENDING: BookingView['status'][] = ['held', 'awaiting_verification'];

export function useBooking(ref: string, token: string) {
  return useQuery({
    queryKey: keys.booking(ref),
    queryFn: ({ signal }) =>
      apiFetch<BookingView>(`/bookings/${encodeURIComponent(ref)}?t=${encodeURIComponent(token)}`, {
        signal,
      }),
    enabled: token !== '',
    retry: (count, error) =>
      count < 2 &&
      !(
        error instanceof Error &&
        'status' in error &&
        (error as { status: number }).status === 404
      ),
    refetchInterval: (query) =>
      query.state.data && PENDING.includes(query.state.data.status) ? PENDING_POLL_MS : false,
  });
}

export function useSubmitUtr(ref: string, token: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (utr: string) =>
      apiFetch<BookingView>(`/bookings/${encodeURIComponent(ref)}/utr`, {
        method: 'POST',
        json: { token, utr },
      }),
    onSuccess: (booking) => client.setQueryData(keys.booking(ref), booking),
  });
}

export function usePayOnline(ref: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (token: string) =>
      apiFetch<BookingView>(`/bookings/${encodeURIComponent(ref)}/razorpay`, {
        method: 'POST',
        json: { token },
      }),
    onSuccess: (booking) => client.setQueryData(keys.booking(ref), booking),
  });
}

export function useRazorpayReturn(ref: string) {
  return useMutation({
    mutationFn: (params: Record<string, string>) =>
      apiFetch<RazorpayReturn>(`/bookings/${encodeURIComponent(ref)}/razorpay/return`, {
        method: 'POST',
        json: params,
      }),
  });
}
