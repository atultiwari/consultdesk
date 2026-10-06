import type { PaymentMethod, Question } from '../api/types';

export type Role = 'owner' | 'admin' | 'provider';

export type AdminUser = {
  id: number;
  email: string;
  name: string | null;
  role: Role;
  provider_id: number | null;
};

export type AdminSession = { user: AdminUser; csrf_token: string };

export type BookingStatus =
  | 'held'
  | 'awaiting_verification'
  | 'confirmed'
  | 'rejected'
  | 'expired'
  | 'cancelled'
  | 'completed'
  | 'no_show'
  | 'rescheduled';

export type BookingAction = 'confirm' | 'reject' | 'cancel' | 'complete' | 'no-show';

export type BookingRow = {
  id: number;
  ref: string;
  status: BookingStatus;
  payment_method: PaymentMethod;
  start: string;
  end: string;
  customer_name: string;
  customer_email: string;
  amount_minor: number;
  currency: string;
  utr: string | null;
  hold_expires_at: string | null;
  provider: { id: number; name: string };
  service_title: string;
};

export type HistoryEntry = {
  action: string;
  actor_type: 'user' | 'system' | 'telegram' | 'webhook' | 'customer';
  actor: string | null;
  data: Record<string, unknown>;
  at: string;
};

export type BookingDetail = BookingRow & {
  customer: { name: string; email: string; phone: string | null; timezone: string | null };
  answers: { id: string; label: string; value: unknown }[];
  meet_url: string | null;
  confirmed_at: string | null;
  history: HistoryEntry[];
  actions: BookingAction[];
};

export type Dashboard = {
  to_verify: BookingRow[];
  to_approve: BookingRow[];
  today: BookingRow[];
  upcoming: BookingRow[];
};

export type BookingRules = {
  min_notice_min: number;
  horizon_days: number;
  buffer_before: number;
  buffer_after: number;
  slot_interval: number;
  max_per_day: number | null;
};

export type AdminProvider = {
  id: number;
  slug: string;
  name: string;
  title: string | null;
  bio: string | null;
  timezone: string;
  active: boolean;
  sort_order: number;
  whatsapp: string | null;
  notify_email: string | null;
  upi_vpa: string | null;
  upi_payee_name: string | null;
  telegram_linked: boolean;
  rules: BookingRules;
};

export type AdminService = {
  id: number;
  provider_id: number;
  slug: string;
  title: string;
  tagline: string | null;
  description: string | null;
  audience: string | null;
  duration_min: number;
  price_minor: number;
  currency: string;
  requires_approval: boolean;
  payment_methods: PaymentMethod[];
  questions: Question[];
  active: boolean;
  sort_order: number;
};

export type HoursWindow = {
  id?: number;
  weekday: number;
  start: string;
  end: string;
  service_id: number | null;
};

export type BlockedTime = {
  id: number;
  provider_id: number | null;
  provider_name: string | null;
  start: string;
  end: string;
  all_day: boolean;
  reason: string | null;
};
