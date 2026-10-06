// Thin fetch wrapper for the ConsultDesk API envelope: { success, data, error, meta }.

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

type Envelope<T> =
  | { success: true; data: T; error: null }
  | {
      success: false;
      data: null;
      error: { code: string; message: string; fields?: Record<string, string> };
    };

export type RequestOptions = { method?: 'GET' | 'POST'; json?: unknown; signal?: AbortSignal };

export const API_BASE = '/api';

export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.json !== undefined) headers['Content-Type'] = 'application/json';

  let response: Response;
  try {
    response = await fetch(`${API_BASE}${path}`, {
      method: options.method ?? 'GET',
      headers,
      body: options.json === undefined ? undefined : JSON.stringify(options.json),
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

  return body.data;
}
