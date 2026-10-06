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
  gateway_payment_id?: string | null;
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
  photo_url?: string | null;
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

export type UserStatus = 'active' | 'invited' | 'invite_expired' | 'disabled';

export type ManagedUser = {
  id: number;
  email: string;
  name: string | null;
  role: Role;
  provider: { id: number; name: string } | null;
  status: UserStatus;
  last_login_at: string | null;
  telegram_linked: boolean;
};

export type Branding = {
  org_name: string;
  preset: 'neutral' | 'he' | 'vrl';
  accent: string | null;
  accent_2: string | null;
  logo_url: string | null;
};

export type Integrations = {
  telegram: { configured: boolean; linked: boolean };
  google: {
    configured: boolean;
    connected: boolean;
    active?: boolean;
    account_email?: string | null;
    busy_calendar_ids?: string[];
    target_calendar_id?: string | null;
  };
};

export type GoogleCalendarOption = {
  id: string;
  summary: string;
  primary: boolean;
  writable: boolean;
};

export type SystemSnapshot = {
  version: string;
  php: string;
  migrations_pending: string[];
  cron: { last_run_at: string | null; healthy: boolean };
  outbox: { pending: number; failed: number; last_error: string | null };
};

export type PaymentSettings = {
  methods: { upi_enabled: boolean; razorpay_enabled: boolean };
  razorpay: {
    configured: boolean;
    mode: 'test' | 'live' | null;
    key_id: string | null;
    has_webhook_secret: boolean;
    webhook_url: string;
    live_allowed: boolean;
    /** Only in the answer that made it: shown once, to paste into Razorpay. */
    webhook_secret?: string;
  };
  overrides: {
    provider_id: number;
    provider_name: string;
    key_id: string;
    has_webhook_secret: boolean;
  }[];
};

export type StarterTemplate = {
  key: string;
  title: string;
  tagline: string;
  audience: string | null;
  duration_min: number;
  price_minor: number;
  /** The researched price band, e.g. "Students ₹999 · professionals ₹1,999". */
  price_note: string | null;
  requires_approval: boolean;
};

export type SetupState = {
  mode: 'single' | 'multi' | null;
  completed: boolean;
  provider: AdminProvider | null;
  template_sets: {
    key: string;
    label: string;
    description: string;
    templates: StarterTemplate[];
  }[];
};
