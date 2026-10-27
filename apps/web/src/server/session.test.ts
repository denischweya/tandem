import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Not written in round 1 - that report claimed `getSession()` could only be "verified by
 * inspection" because `next/headers` "needs a real Next request scope this repo's Vitest
 * config deliberately does not provide." MEASURED in fix round 2 that this claim was wrong:
 * `next/headers`'s `headers()` is an ordinary named export, mockable exactly like
 * `./apiFetch`'s `callApi` is in `handlers.test.ts` - no real Next request scope needed for a
 * plain READ. `vi.mock('server-only', …)` is required for the same reason it is in
 * `env.test.ts`/`apiFetch.test.ts`: the real package throws unconditionally outside a
 * bundler alias, and `session.ts` imports it.
 */
vi.mock('server-only', () => ({}));
vi.mock('next/headers', () => ({ headers: vi.fn() }));
vi.mock('./apiFetch', () => ({ callApi: vi.fn() }));

import { headers as headersImpl } from 'next/headers';
import { callApi as callApiImpl } from './apiFetch';
import { getSession, SessionReadError } from './session';

const headersMock = vi.mocked(headersImpl);
const callApi = vi.mocked(callApiImpl);

const USER = {
  id: '0199e2a0-7b1c-7000-8000-000000000001',
  email: 'ada@example.test',
  email_verified: true,
  display_name: 'Ada Lovelace',
  timezone: 'Africa/Nairobi',
  locale: 'en',
};

function fakeHeaders(cookie: string | null): Headers {
  const h = new Headers();
  if (cookie !== null) h.set('cookie', cookie);
  return h;
}

beforeEach(() => {
  callApi.mockReset();
  headersMock.mockReset();
});

describe('getSession', () => {
  it('reports signedOut with no API call when there is no access cookie', async () => {
    headersMock.mockResolvedValue(fakeHeaders(null));
    await expect(getSession()).resolves.toEqual({ status: 'signedOut' });
    expect(callApi).not.toHaveBeenCalled();
  });

  it('reports signedIn with the user on a live access cookie', async () => {
    headersMock.mockResolvedValue(fakeHeaders('tandem_at=uuid%7Cabc'));
    callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: USER } });

    await expect(getSession()).resolves.toEqual({ status: 'signedIn', user: USER });
    expect(callApi.mock.calls[0]?.[0]).toBe('/me');
    expect(callApi.mock.calls[0]?.[1]).toMatchObject({ bearer: 'uuid|abc' });
  });

  it('reports expired on a 401, and ONLY a 401', async () => {
    headersMock.mockResolvedValue(fakeHeaders('tandem_at=uuid%7Cabc'));
    callApi.mockResolvedValue({
      kind: 'error',
      status: 401,
      envelope: { message: 'Unauthenticated.' },
      retryAfterSeconds: null,
    });

    await expect(getSession()).resolves.toEqual({ status: 'expired' });
  });

  it.each([400, 403, 404, 405, 429, 500, 502, 503])(
    'throws SessionReadError rather than reporting expired for a non-401 error status (%i) (fix round 2, Important 3 reopened)',
    async (status) => {
      // The reviewer's own measurement: `getSession 429 -> {"status":"expired"}`,
      // `getSession 500 -> {"status":"expired"}`, `getSession 503 -> {"status":"expired"}` -
      // all before this fix, all wrong for the identical reason `sessionHandler`'s Important 3
      // fix already closed on the client-fetched path: `expired` drives a browser refresh
      // attempt for a credential that was never actually invalid.
      headersMock.mockResolvedValue(fakeHeaders('tandem_at=uuid%7Cabc'));
      callApi.mockResolvedValue({
        kind: 'error',
        status,
        envelope: { message: 'Service Unavailable' },
        retryAfterSeconds: null,
      });

      await expect(getSession()).rejects.toBeInstanceOf(SessionReadError);
      await expect(getSession()).rejects.not.toEqual({ status: 'expired' });
    },
  );

  it('throws SessionReadError rather than reporting expired on a transport failure', async () => {
    headersMock.mockResolvedValue(fakeHeaders('tandem_at=uuid%7Cabc'));
    callApi.mockResolvedValue({ kind: 'transportFailure', error: new Error('ECONNREFUSED') });

    await expect(getSession()).rejects.toBeInstanceOf(SessionReadError);
  });

  it('throws SessionReadError rather than reporting expired when a 2xx body fails to parse', async () => {
    headersMock.mockResolvedValue(fakeHeaders('tandem_at=uuid%7Cabc'));
    callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: { not: 'a user' } } });

    await expect(getSession()).rejects.toBeInstanceOf(SessionReadError);
  });
});
