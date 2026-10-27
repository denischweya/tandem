import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * FIX ROUND 1, MINOR: not in the brief's file list and not written in the original pass -
 * `apiFetch.ts` is the single outbound caller every one of the eight handlers goes through,
 * and it had no test of its own at all. `vi.mock('server-only', …)` is load-bearing here for
 * the same measured reason it is in `env.test.ts`: the real `server-only` package throws
 * unconditionally outside a bundler alias, and `apiFetch.ts` imports `./env`, which imports
 * it.
 */
vi.mock('server-only', () => ({}));

const { callApi } = await import('./apiFetch');

beforeEach(() => {
  vi.unstubAllGlobals();
  delete process.env.API_BASE_URL;
});

describe('callApi', () => {
  it('joins apiBaseUrl() and the given path, with no double slash', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    await callApi('/auth/login', { method: 'POST', body: { a: 1 } });

    expect(fetchMock.mock.calls[0]?.[0]).toBe('http://localhost:8000/api/v1/auth/login');
  });

  it('respects a configured API_BASE_URL', async () => {
    process.env.API_BASE_URL = 'https://api.example.test/api/v1/';
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    await callApi('/me', { method: 'GET' });

    // apiBaseUrl() strips the trailing slash; the join must not reintroduce a double one.
    expect(fetchMock.mock.calls[0]?.[0]).toBe('https://api.example.test/api/v1/me');
  });

  it('sends the method, a JSON content-type and the serialised body when a body is given', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    await callApi('/auth/login', { method: 'POST', body: { email: 'ada@example.test' } });

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit & { headers: Record<string, string> };
    expect(init.method).toBe('POST');
    expect(init.headers['content-type']).toBe('application/json');
    expect(init.headers.accept).toBe('application/json');
    expect(init.body).toBe(JSON.stringify({ email: 'ada@example.test' }));
  });

  it('sends no body and no content-type header when none is given', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    await callApi('/me', { method: 'GET' });

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit & { headers: Record<string, string> };
    expect(init.body).toBeUndefined();
    expect(init.headers['content-type']).toBeUndefined();
  });

  it('attaches the bearer verbatim, never splitting on its pipe', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    await callApi('/me', { method: 'GET', bearer: 'uuid|abcdef' });

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit & { headers: Record<string, string> };
    expect(init.headers.authorization).toBe('Bearer uuid|abcdef');
  });

  it('sends no Authorization header when bearer is null or omitted', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    await callApi('/me', { method: 'GET', bearer: null });

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit & { headers: Record<string, string> };
    expect(init.headers.authorization).toBeUndefined();
  });

  it('never reads the body of a 204 (empty-body) response', async () => {
    const response = new Response(null, { status: 204 });
    const textSpy = vi.spyOn(response, 'text');
    const fetchMock = vi.fn().mockResolvedValue(response);
    vi.stubGlobal('fetch', fetchMock);

    const result = await callApi('/auth/logout', { method: 'POST', bearer: 'x', body: { a: 1 } });

    expect(textSpy).not.toHaveBeenCalled();
    expect(result).toEqual({ kind: 'ok', status: 204, json: null });
  });

  it('treats a 200 with an empty (non-204) body as json: null, not a parse error', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('', { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await callApi('/me', { method: 'GET' });

    expect(result).toEqual({ kind: 'ok', status: 200, json: null });
  });

  it('returns the parsed JSON body on a 2xx', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response(JSON.stringify({ data: { id: '1' } }), { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await callApi('/me', { method: 'GET' });

    expect(result).toEqual({ kind: 'ok', status: 200, json: { data: { id: '1' } } });
  });

  it('swallows a non-JSON body (an HTML proxy error page) to json: null rather than throwing', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(new Response('<html>502 Bad Gateway</html>', { status: 502 }));
    vi.stubGlobal('fetch', fetchMock);

    const result = await callApi('/me', { method: 'GET' });

    // §135: the raw HTML must never surface as the envelope - envelope: null is what makes
    // userMessageFor substitute house copy instead of rendering it.
    expect(result).toEqual({ kind: 'error', status: 502, envelope: null, retryAfterSeconds: null });
  });

  it('parses a valid error envelope on a non-2xx', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 }),
      );
    vi.stubGlobal('fetch', fetchMock);

    const result = await callApi('/me', { method: 'GET' });

    expect(result).toEqual({
      kind: 'error',
      status: 401,
      envelope: { message: 'Unauthenticated.' },
      retryAfterSeconds: null,
    });
  });

  it('parses Retry-After when present', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ message: 'Too Many Attempts.' }), {
        status: 429,
        headers: { 'retry-after': '540' },
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const result = await callApi('/auth/login', { method: 'POST', body: {} });

    expect(result).toMatchObject({ retryAfterSeconds: 540 });
  });

  it('treats an unparsable Retry-After as null rather than NaN', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ message: 'Too Many Attempts.' }), {
        status: 429,
        headers: { 'retry-after': 'not-a-number' },
      }),
    );
    vi.stubGlobal('fetch', fetchMock);

    const result = await callApi('/auth/login', { method: 'POST', body: {} });

    expect(result).toMatchObject({ retryAfterSeconds: null });
  });

  it('returns transportFailure, with the raw error, when fetch itself throws', async () => {
    const thrown = new TypeError('fetch failed');
    const fetchMock = vi.fn().mockRejectedValue(thrown);
    vi.stubGlobal('fetch', fetchMock);

    const result = await callApi('/me', { method: 'GET' });

    expect(result).toEqual({ kind: 'transportFailure', error: thrown });
  });
});
