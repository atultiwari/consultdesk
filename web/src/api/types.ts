export type Preset = 'neutral' | 'he' | 'vrl';

export type Site = {
  org_name: string;
  preset: Preset;
  accent: string | null;
  accent_2: string | null;
  logo_url: string | null;
  single_provider: string | null;
};

export type ProviderProfile = {
  slug: string;
  name: string;
  title: string | null;
  bio: string | null;
  photo_url: string | null;
  timezone: string;
};

export type QuestionType = 'text' | 'textarea' | 'select' | 'url' | 'checkbox';

export type Question = {
  id: string;
  label: string;
  type: QuestionType;
  required: boolean;
  options?: string[];
};

export type PaymentMethod = 'upi' | 'free' | 'razorpay_link';

export type Service = {
  slug: string;
  title: string;
  tagline: string | null;
  description: string | null;
  audience: string | null;
  duration_minutes: number;
  price_minor: number;
  currency: string;
  price_display: string;
  requires_approval: boolean;
  payment_methods: PaymentMethod[];
  questions: Question[];
};

export type ProviderDetail = { provider: ProviderProfile; services: Service[] };

export type SlotsResponse = {
  timezone: string;
  from: string;
  to: string;
  slots: { start: string; end: string }[];
};

export type BookingState =
  | 'held'
  | 'awaiting_verification'
  | 'confirmed'
  | 'rejected'
  | 'expired'
  | 'cancelled'
  | 'completed'
  | 'no_show'
  | 'rescheduled';

export type UpiPayment = {
  method: 'upi';
  available: boolean;
  vpa?: string;
  payee_name?: string;
  amount?: string;
  amount_display?: string;
  upi_uri?: string;
  whatsapp_url?: string | null;
  can_submit_utr: boolean;
  utr_single_use: boolean;
};

export type BookingView = {
  ref: string;
  status: BookingState;
  payment_method: PaymentMethod;
  start: string;
  end: string;
  timezone: string;
  duration_minutes: number;
  service: { title: string };
  provider: { name: string; slug: string };
  customer_name: string;
  amount_minor: number;
  currency: string;
  amount_display: string;
  hold_expires_at: string | null;
  utr: string | null;
  meet_url: string | null;
  payment: UpiPayment | RazorpayPayment | null;
};

export type RazorpayPayment = {
  method: 'razorpay_link';
  /** Razorpay's page for this booking; null until made (e.g. if Razorpay was briefly unreachable). */
  pay_url: string | null;
  amount_display: string;
};

export type RazorpayReturn = { ref: string; status: BookingState };

export type CreatedBooking = {
  ref: string;
  token: string;
  status_url: string;
  booking: BookingView;
};

export type NewBooking = {
  provider: string;
  service: string;
  start: string;
  payment_method: PaymentMethod;
  customer: { name: string; email: string; phone: string; timezone: string };
  answers: Record<string, string | boolean>;
  website: string;
};
