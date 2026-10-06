import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError } from '../api/client';
import { adminFetch } from './api';
import type { PaymentSettings } from './types';

const KEY = ['admin', 'payments'] as const;

const once = (count: number, error: Error) =>
  count < 1 && !(error instanceof ApiError && error.status >= 400 && error.status < 500);

export function usePaymentSettings() {
  return useQuery({
    queryKey: KEY,
    queryFn: ({ signal }) => adminFetch<PaymentSettings>('/payments', { signal }),
    retry: once,
  });
}

/** Saves and keeps the answer (it may carry a one-time webhook secret to show). */
function usePaymentsMutation<T>(run: (input: T) => Promise<PaymentSettings>) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: run,
    onSuccess: async (saved) => {
      client.setQueryData(KEY, {
        ...saved,
        razorpay: { ...saved.razorpay, webhook_secret: undefined },
      });
      // Which ways to pay are offered shows on the public pages.
      await Promise.all([
        client.invalidateQueries({ queryKey: ['provider'] }),
        client.invalidateQueries({ queryKey: ['providers'] }),
      ]);
    },
  });
}

export function useSaveMethods() {
  return usePaymentsMutation((methods: PaymentSettings['methods']) =>
    adminFetch<PaymentSettings>('/payments/methods', { method: 'PUT', json: methods }),
  );
}

export function useSaveOrgKeys() {
  return usePaymentsMutation((keys: { key_id: string; key_secret: string }) =>
    adminFetch<PaymentSettings>('/payments/razorpay', { method: 'PUT', json: keys }),
  );
}

export function useCheckOrgKeys() {
  return usePaymentsMutation(() =>
    adminFetch<PaymentSettings>('/payments/razorpay/check', { method: 'POST' }),
  );
}

export function useNewWebhookSecret() {
  return usePaymentsMutation(() =>
    adminFetch<PaymentSettings>('/payments/razorpay/webhook-secret', { method: 'POST' }),
  );
}

export function useRemoveOrgKeys() {
  return usePaymentsMutation(() =>
    adminFetch<PaymentSettings>('/payments/razorpay', { method: 'DELETE' }),
  );
}

export function useSaveProviderKeys() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({
      providerId,
      ...keys
    }: {
      providerId: number;
      key_id: string;
      key_secret: string;
    }) =>
      adminFetch<{ provider_id: number; key_id: string; webhook_secret: string }>(
        `/providers/${providerId}/razorpay`,
        {
          method: 'PUT',
          json: keys,
        },
      ),
    onSuccess: () => client.invalidateQueries({ queryKey: KEY }),
  });
}

export function useRemoveProviderKeys() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (providerId: number) =>
      adminFetch<{ ok: true }>(`/providers/${providerId}/razorpay`, { method: 'DELETE' }),
    onSuccess: () => client.invalidateQueries({ queryKey: KEY }),
  });
}
