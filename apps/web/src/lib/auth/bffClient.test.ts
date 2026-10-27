import { afterEach, describe, expect, it, vi } from 'vitest';

import { HOUSE_NETWORK_MESSAGE } from '@/server/errors';

import { postJson, retryAfterSuffix, submitBff } from './bffClient';

function jsonResponse(status: number, body: unknown, headers?: Record<string, string>): Response {
  return new Response(JSON.stringify(body), {
    status,
    ...(headers !== undefined ? { headers } : {}),
  });
}

function noBodyResponse(status: number): Response {
  return new Response(null, { status });
}

describe('retryAfterSuffix', () => {
  // Mirrors tandem-mobile/src/features/auth/screenLogic.ts's retryAfterSuffix exactly - same
  // rounding, same copy, so the same Retry-After seconds read as the same promise on both
  // clients.
  it('rounds up to the nearest whole minute', () => {
    expect(retryAfterSuffix(61)).toBe(' Try again in 2 minutes.');
    expect(retryAfterSuffix(120)).toBe(' Try again in 2 minutes.');
  });

  it('floors at one minute rather than reporting zero', () => {
    expect(retryAfterSuffix(0)).toBe(' Try again in 1 minute.');
    expect(retryAfterSuffix(5)).toBe(' Try again in 1 minute.');
  });

  it('uses the singular only for exactly one minute', () => {
    expect(retryAfterSuffix(60)).toBe(' Try again in 1 minute.');
    expect(retryAfterSuffix(90)).toBe(' Try again in 2 minutes.');
  });
});

describe('submitBff / postJson', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('reports ok with the parsed body on a 200', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, { user: { id: '1' } })));

    const result = await postJson('/api/auth/login', { email: 'a@b.test', password: 'x' });

    expect(result).toEqual({ kind: 'ok', status: 200, json: { user: { id: '1' } } });
  });

  it('reports ok with a null body on a 204 (reset-password) and a 202 (forgot-password)', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(noBodyResponse(204)));
    expect(await postJson('/api/auth/reset-password', {})).toEqual({
      kind: 'ok',
      status: 204,
      json: null,
    });

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(noBodyResponse(202)));
    expect(await postJson('/api/auth/forgot-password', {})).toEqual({
      kind: 'ok',
      status: 202,
      json: null,
    });
  });

  it('reports a transport failure (fetch itself throws) as the network house copy', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

    const result = await postJson('/api/auth/login', { email: 'a@b.test', password: 'x' });

    expect(result).toEqual({
      kind: 'failure',
      failure: {
        status: 0,
        message: "We couldn't reach Tandem. Check your connection and try again.",
        fieldErrors: null,
      },
    });
  });

  /**
   * Claim #1 from the task brief, pinned on the CLIENT side the same way
   * src/server/handlers.test.ts pins it on the server side: the `errors` map is bare strings.
   * `tsc` cannot pin this (BffFailureBodySchema's inferred type is a compile-time fact about
   * THIS file agreeing with itself, not about what actually arrives over the wire) - this
   * runtime test is the guard.
   */
  it('reads a 422 with bare-string field errors (the real BFF shape)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(422, {
          message: 'The given data was invalid.',
          errors: { email: 'has already been taken' },
        }),
      ),
    );

    const result = await postJson('/api/auth/register', {});

    expect(result.kind).toBe('failure');
    if (result.kind !== 'failure') throw new Error('unreachable');
    expect(result.failure.fieldErrors).toEqual({ email: 'has already been taken' });
  });

  it('degrades to house copy, rather than crashing, if `errors` ever regressed to arrays', async () => {
    // Simulates the EXACT pre-fix shape claim #1 closed - `errors.email` as `string[]`. The
    // point of this test is not that this shape should be supported (it must not be: "Do not
    // loosen it") but that the client fails SAFE rather than reading `messages[0]` as a
    // character off a string, or crashing, or leaking something unparsed.
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(422, {
          message: 'The given data was invalid.',
          errors: { email: ['has already been taken'] },
        }),
      ),
    );

    const result = await postJson('/api/auth/register', {});

    expect(result.kind).toBe('failure');
    if (result.kind !== 'failure') throw new Error('unreachable');
    expect(result.failure.fieldErrors).toBeNull();
    expect(result.failure.message).toBe('Something went wrong on our end. Please try again.');
  });

  it('renders the API-authored message verbatim for a 401 with no field to blame', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse(401, { message: 'These credentials do not match our records.' }),
        ),
    );

    const result = await postJson('/api/auth/login', { email: 'a@b.test', password: 'x' });

    expect(result.kind).toBe('failure');
    if (result.kind !== 'failure') throw new Error('unreachable');
    expect(result.failure.fieldErrors).toBeNull();
    expect(result.failure.message).toBe('These credentials do not match our records.');
  });

  it('appends "Try again in N minutes" to a 429, and shows no attempts count', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(
          jsonResponse(429, { message: 'Too Many Attempts.' }, { 'retry-after': '540' }),
        ),
    );

    const result = await postJson('/api/auth/login', { email: 'a@b.test', password: 'x' });

    expect(result.kind).toBe('failure');
    if (result.kind !== 'failure') throw new Error('unreachable');
    expect(result.failure.message).toBe('Too Many Attempts. Try again in 9 minutes.');
    expect(result.failure.message).not.toMatch(/\d+ attempts? (left|remaining)/i);
  });

  /**
   * Defence in depth, not redundancy: `relayFailure` (handlers.ts) already gates `message`
   * through `userMessageFor`'s allowlist server-side, so this status/body combination cannot
   * actually arrive from THIS BFF today. This test exists for the BFF that might regress -
   * re-applying the identical allowlist client-side means a future server-side bug stops
   * here too, not only at the layer that just failed.
   */
  it('substitutes house copy for an unauthored status even if the body looks authored', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse(500, { message: 'SQLSTATE[23000]: raw db text' })),
    );

    const result = await postJson('/api/auth/login', { email: 'a@b.test', password: 'x' });

    expect(result.kind).toBe('failure');
    if (result.kind !== 'failure') throw new Error('unreachable');
    expect(result.failure.message).toBe('Something went wrong on our end. Please try again.');
    expect(result.failure.message).not.toContain('SQLSTATE');
  });

  /**
   * Wave 1b final review, W5/D1 - THE COMPOSITION TEST neither half of the original bug had.
   * `handlers.test.ts` asserts the SERVER's 502 body (`relayFailure`'s transportFailure
   * branch: `{message: HOUSE_NETWORK_MESSAGE}`); the "substitutes house copy for an
   * unauthored status" test two above asserts this CLIENT's re-gate on a status it should
   * NOT trust. Neither one, on its own, exercises what happens when THIS BFF's own authored
   * 502 reaches THIS re-gate - which is exactly the composition that discarded
   * `HOUSE_NETWORK_MESSAGE` and substituted `HOUSE_SERVER_MESSAGE` ("something went wrong on
   * our end") for a connectivity problem, before the fix.
   */
  it('relays a 502 transport-failure body (the BFF own-authored message) verbatim, not house-copy-over-house-copy', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse(502, { message: HOUSE_NETWORK_MESSAGE })),
    );

    const result = await postJson('/api/auth/login', { email: 'a@b.test', password: 'x' });

    expect(result.kind).toBe('failure');
    if (result.kind !== 'failure') throw new Error('unreachable');
    expect(result.failure.message).toBe(HOUSE_NETWORK_MESSAGE);
  });

  it('verify-email relay sends no body and no content-type - submitBff passes init through verbatim', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, { user: { id: '1' } }));
    vi.stubGlobal('fetch', fetchMock);

    await submitBff('/api/auth/verify-email?id=1&hash=h&expires=1&signature=s', {
      method: 'POST',
    });

    expect(fetchMock).toHaveBeenCalledWith(
      '/api/auth/verify-email?id=1&hash=h&expires=1&signature=s',
      { method: 'POST' },
    );
  });
});
