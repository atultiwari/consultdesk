import { fail, ok, site, type Routes } from '../support';

export const ADMIN = '/desk-7q2x-placeholder';

export const owner = {
  id: 1,
  email: 'owner@example.test',
  name: 'Site Owner',
  role: 'owner',
  provider_id: null,
};
export const providerUser = {
  id: 2,
  email: 'demo@example.test',
  name: null,
  role: 'provider',
  provider_id: 7,
};

export const session = (user: typeof owner | typeof providerUser = owner) =>
  ok({ user, csrf_token: 'csrf-token-1' });

/** Routes every admin page needs: the site, the secret path check and a signed-in session. */
export function signedIn(user: typeof owner | typeof providerUser = owner): Routes {
  return {
    'GET /api/site': ok(site),
    'GET /api/admin/entry/desk-7q2x-placeholder': ok({ ok: true }),
    'GET /api/admin/me': session(user),
  };
}

export const signedOut: Routes = {
  'GET /api/site': ok(site),
  'GET /api/admin/entry/desk-7q2x-placeholder': ok({ ok: true }),
  'GET /api/admin/me': fail(401, 'unauthenticated', 'Please sign in again.'),
};

export const row = {
  id: 11,
  ref: 'CD-7F3K',
  status: 'awaiting_verification',
  payment_method: 'upi',
  start: '2026-10-07T04:30:00Z',
  end: '2026-10-07T05:30:00Z',
  customer_name: 'Asha Placeholder',
  customer_email: 'asha@example.test',
  amount_minor: 299900,
  currency: 'INR',
  utr: '412345678901',
  hold_expires_at: '2026-10-06T00:00:00Z',
  provider: { id: 7, name: 'Dr. Demo' },
  service_title: 'Thesis guidance',
};

export const detail = {
  ...row,
  customer: {
    name: 'Asha Placeholder',
    email: 'asha@example.test',
    phone: '+910000000000',
    timezone: 'Asia/Kolkata',
  },
  answers: [{ id: 'goal', label: 'Goal', value: 'Feedback on chapter 2' }],
  meet_url: null,
  confirmed_at: null,
  history: [
    {
      action: 'booking.held',
      actor_type: 'customer',
      actor: null,
      data: {},
      at: '2026-10-05T00:00:00Z',
    },
    {
      action: 'booking.utr_submitted',
      actor_type: 'customer',
      actor: null,
      data: { utr: '412345678901' },
      at: '2026-10-05T00:05:00Z',
    },
  ],
  actions: ['confirm', 'reject', 'cancel'],
};

export const demoProvider = {
  id: 7,
  slug: 'demo',
  name: 'Dr. Demo',
  title: 'Pathologist',
  bio: null,
  timezone: 'Asia/Kolkata',
  active: true,
  sort_order: 0,
  whatsapp: null,
  notify_email: null,
  upi_vpa: 'placeholder@upi',
  upi_payee_name: 'Placeholder Payee',
  telegram_linked: false,
  rules: {
    min_notice_min: 1440,
    horizon_days: 30,
    buffer_before: 10,
    buffer_after: 10,
    slot_interval: 30,
    max_per_day: 3,
  },
};

export const thesisService = {
  id: 21,
  provider_id: 7,
  slug: 'thesis',
  title: 'Thesis guidance',
  tagline: null,
  description: null,
  audience: null,
  highlight: null,
  duration_min: 60,
  price_minor: 299900,
  currency: 'INR',
  requires_approval: false,
  payment_methods: ['upi'],
  questions: [{ id: 'goal', label: 'Goal', type: 'textarea', required: true }],
  active: true,
  sort_order: 0,
};

/** The X-CSRF-Token header a mocked request was sent with. */
export function csrfOf(init: RequestInit | undefined): string | null {
  return new Headers(init?.headers).get('X-CSRF-Token');
}
