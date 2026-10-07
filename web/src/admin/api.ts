// Admin API calls: the session cookie travels automatically; writes also carry the CSRF token
// that came with the session.

import { apiDownload, apiFetch, apiRequest, type RequestOptions } from '../api/client';

let csrfToken = '';

export function rememberCsrf(token: string): void {
  csrfToken = token;
}

function withCsrf(options: RequestOptions): RequestOptions {
  const method = options.method ?? 'GET';
  return method === 'GET'
    ? options
    : { ...options, headers: { ...options.headers, 'X-CSRF-Token': csrfToken } };
}

export function adminFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  return apiFetch<T>(`/admin${path}`, withCsrf(options));
}

export function adminRequest<T>(path: string, options: RequestOptions = {}) {
  return apiRequest<T>(`/admin${path}`, withCsrf(options));
}

export function adminDownload(path: string, options: RequestOptions = {}) {
  return apiDownload(`/admin${path}`, withCsrf(options));
}
