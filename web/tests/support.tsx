import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router';
import { AppRoutes } from '../src/app/App';

type Handler = (init: RequestInit | undefined, url: URL) => { status?: number; body: unknown };
export type Routes = Record<string, Handler | { status?: number; body: unknown }>;

/** Mocks fetch: keys are "METHOD /api/path" (query string ignored). Unmatched requests fail the test. */
export function mockApi(routes: Routes) {
  const calls: { method: string; path: string; body: unknown }[] = [];
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input), 'http://localhost');
    const method = init?.method ?? 'GET';
    const key = `${method} ${url.pathname}`;
    calls.push({
      method,
      path: url.pathname + url.search,
      body: init?.body ? JSON.parse(String(init.body)) : undefined,
    });
    const route = routes[key];
    if (!route) throw new Error(`Unmocked request: ${key}`);
    const { status = 200, body } = typeof route === 'function' ? route(init, url) : route;
    return new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
  });
  vi.stubGlobal('fetch', fetchMock);
  return calls;
}

export const ok = (data: unknown, status = 200) => ({
  status,
  body: { success: true, data, error: null, meta: null },
});
export const fail = (
  status: number,
  code: string,
  message: string,
  fields?: Record<string, string>,
) => ({
  status,
  body: {
    success: false,
    data: null,
    error: { code, message, ...(fields ? { fields } : {}) },
    meta: null,
  },
});

let currentPath = '';
function LocationProbe() {
  const location = useLocation();
  currentPath = location.pathname + location.search;
  return null;
}
export const path = () => currentPath;

export function renderAt(entry: string) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[entry]}>
        <AppRoutes />
        <LocationProbe />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

export const site = {
  org_name: 'Dr. Demo Bookings',
  preset: 'he',
  accent: null,
  accent_2: null,
  logo_url: null,
  single_provider: null,
};
export const provider = {
  slug: 'demo',
  name: 'Dr. Demo',
  title: 'Pathologist',
  bio: 'Helps with research.',
  photo_url: null,
  timezone: 'Asia/Kolkata',
};
export const thesis = {
  slug: 'thesis',
  title: 'Thesis guidance',
  tagline: 'Study design and methods.',
  description: null,
  audience: 'residents',
  duration_minutes: 60,
  price_minor: 299900,
  currency: 'INR',
  price_display: '₹2,999',
  requires_approval: false,
  payment_methods: ['upi'],
  questions: [
    { id: 'goal', label: 'What do you want to walk away with?', type: 'textarea', required: true },
    { id: 'consent', label: 'No patient data', type: 'checkbox', required: true },
  ],
};
export const heldBooking = {
  ref: 'CD-7F3K',
  status: 'held',
  payment_method: 'upi',
  start: '2026-10-07T04:30:00Z',
  end: '2026-10-07T05:30:00Z',
  timezone: 'Asia/Kolkata',
  duration_minutes: 60,
  service: { title: 'Thesis guidance' },
  provider: { name: 'Dr. Demo', slug: 'demo' },
  customer_name: 'Asha Placeholder',
  amount_minor: 299900,
  currency: 'INR',
  amount_display: '₹2,999',
  hold_expires_at: '2026-10-05T01:00:00Z',
  utr: null,
  meet_url: null,
  payment: {
    method: 'upi',
    available: true,
    vpa: 'placeholder@upi',
    payee_name: 'Demo Payee',
    amount: '2999.00',
    amount_display: '₹2,999',
    upi_uri: 'upi://pay?pa=placeholder%40upi&pn=Demo%20Payee&am=2999.00&cu=INR&tn=CD-7F3K',
    whatsapp_url: 'https://wa.me/910000000000?text=hi',
    can_submit_utr: true,
    utr_single_use: true,
  },
};
