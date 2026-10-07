// Thin fetch wrapper for the ConsultDesk API envelope: { success, data, error, meta }.

import { recordServerDate } from '../lib/serverTime';

export class ApiError extends Error {
  readonly status: number;
  readonly code: string;
  readonly fields: Record<string, string>;

  constructor(message: string, status: number, code: string, fields: Record<string, string> = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.fields = fields;
  }
}

export type Meta = { total: number; page: number; per_page: number } | null;

type Envelope<T> =
  | { success: true; data: T; error: null; meta?: Meta }
  | {
      success: false;
      data: null;
      error: { code: string; message: string; fields?: Record<string, string> };
    };

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  json?: unknown;
  /** A file upload; the browser sets the multipart Content-Type itself. */
  form?: FormData;
  signal?: AbortSignal;
  headers?: Record<string, string>;
};

export const API_BASE = '/api';

export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  return (await apiRequest<T>(path, options)).data;
}

/** Like apiFetch, but also returns the envelope's meta (pagination). */
export async function apiRequest<T>(
  path: string,
  options: RequestOptions = {},
): Promise<{ data: T; meta: Meta }> {
  const body = await readEnvelope<T>(await send(path, options));
  return { data: body.data, meta: body.meta ?? null };
}

/** A file the API sends back (e.g. a backup); errors still arrive as the JSON envelope. */
export async function apiDownload(
  path: string,
  options: RequestOptions = {},
): Promise<{ blob: Blob; filename: string }> {
  const response = await send(path, options);
  if (!response.ok || (response.headers.get('Content-Type') ?? '').includes('application/json')) {
    await readEnvelope<never>(response);
  }
  const disposition = response.headers.get('Content-Disposition') ?? '';
  const filename = /filename="([^"]+)"/.exec(disposition)?.[1] ?? 'download';
  return { blob: await response.blob(), filename };
}

async function send(path: string, options: RequestOptions): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json', ...options.headers };
  if (options.json !== undefined) headers['Content-Type'] = 'application/json';

  let response: Response;
  try {
    response = await fetch(`${API_BASE}${path}`, {
      method: options.method ?? 'GET',
      headers,
      body: options.form ?? (options.json === undefined ? undefined : JSON.stringify(options.json)),
      signal: options.signal,
    });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error;
    throw new ApiError(
      'Could not reach the booking service. Check your connection and try again.',
      0,
      'network_error',
    );
  }

  recordServerDate(response.headers.get('Date'));
  return response;
}

/** The success envelope, or an ApiError with the server's message and field errors. */
async function readEnvelope<T>(
  response: Response,
): Promise<{ success: true; data: T; error: null; meta?: Meta }> {
  let body: Envelope<T>;
  try {
    body = (await response.json()) as Envelope<T>;
  } catch {
    throw new ApiError(
      'The booking service is having trouble. Please try again in a moment.',
      response.status,
      'server_error',
    );
  }

  if (!body.success) {
    throw new ApiError(
      body.error.message,
      response.status,
      body.error.code,
      body.error.fields ?? {},
    );
  }

  return body;
}
