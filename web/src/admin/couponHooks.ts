import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminFetch } from './api';
import type { AdminCoupon, CouponInput } from './types';

export const couponKeys = { all: ['admin', 'coupons'] as const };

export function useCoupons() {
  return useQuery({
    queryKey: couponKeys.all,
    queryFn: ({ signal }) => adminFetch<AdminCoupon[]>('/coupons', { signal }),
  });
}

function useCouponMutation<T>(run: (value: T) => Promise<unknown>) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: run,
    onSuccess: () => client.invalidateQueries({ queryKey: couponKeys.all }),
  });
}

export function useSaveCoupon(id: number | null) {
  return useCouponMutation((coupon: CouponInput) =>
    id === null
      ? adminFetch<AdminCoupon>('/coupons', { method: 'POST', json: coupon })
      : adminFetch<AdminCoupon>(`/coupons/${id}`, { method: 'PATCH', json: coupon }),
  );
}

export function useToggleCoupon() {
  return useCouponMutation(({ id, active }: { id: number; active: boolean }) =>
    adminFetch<AdminCoupon>(`/coupons/${id}`, { method: 'PATCH', json: { active } }),
  );
}

export function useDeleteCoupon() {
  return useCouponMutation((id: number) =>
    adminFetch<{ ok: true }>(`/coupons/${id}`, { method: 'DELETE' }),
  );
}
