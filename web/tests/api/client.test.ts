import { ApiError, apiFetch } from '../../src/api/client';

function respond(status: number, body: unknown) {
  return vi.fn().mockResolvedValue(
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    }),
  );
}

afterEach(() => vi.unstubAllGlobals());

describe('apiFetch', () => {
  it('returns the data of a success envelope', async () => {
    vi.stubGlobal(
      'fetch',
      respond(200, { success: true, data: { slug: 'demo' }, error: null, meta: null }),
    );

    await expect(apiFetch<{ slug: string }>('/providers/demo')).resolves.toEqual({ slug: 'demo' });
    expect(fetch).toHaveBeenCalledWith(
      '/api/providers/demo',
      expect.objectContaining({ headers: expect.objectContaining({ Accept: 'application/json' }) }),
    );
  });

  it('sends JSON bodies', async () => {
    vi.stubGlobal('fetch', respond(201, { success: true, data: {}, error: null, meta: null }));

    await apiFetch('/bookings', { method: 'POST', json: { a: 1 } });

    const [, init] = vi.mocked(fetch).mock.calls[0];
    expect(init?.body).toBe('{"a":1}');
    expect((init?.headers as Record<string, string>)['Content-Type']).toBe('application/json');
  });

  it('turns error envelopes into ApiError with field messages', async () => {
    vi.stubGlobal(
      'fetch',
      respond(422, {
        success: false,
        data: null,
        error: {
          code: 'validation_failed',
          message: 'Please check the highlighted fields.',
          fields: { 'customer.email': 'Enter a valid email address.' },
        },
        meta: null,
      }),
    );

    const error = await apiFetch('/bookings', { method: 'POST', json: {} }).catch(
      (e: unknown) => e,
    );

    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({
      status: 422,
      code: 'validation_failed',
      fields: { 'customer.email': 'Enter a valid email address.' },
    });
  });

  it('reports network failures and non-JSON responses as friendly errors', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));
    await expect(apiFetch('/site')).rejects.toMatchObject({ code: 'network_error' });

    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(new Response('<html>502</html>', { status: 502 })),
    );
    await expect(apiFetch('/site')).rejects.toMatchObject({ code: 'server_error', status: 502 });
  });
});
