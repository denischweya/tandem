import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ACCESS_COOKIE, REFRESH_COOKIE } from './cookies';

// vi.mock with a factory, NOT vi.spyOn over a namespace import: an ESM namespace object is
// not extensible, so spyOn on it throws in a plain `environment: 'node'` Vitest run. The
// factory is hoisted above the imports, which is why `callApi` is read back through
// vi.mocked() rather than captured here.
vi.mock('./apiFetch', () => ({ callApi: vi.fn() }));

import { callApi as callApiImpl } from './apiFetch';
import * as handlersModule from './handlers';
import {
  forgotPasswordHandler,
  loginHandler,
  logoutHandler,
  refreshHandler,
  registerHandler,
  resetPasswordHandler,
  sessionHandler,
  verifyEmailHandler,
} from './handlers';

const callApi = vi.mocked(callApiImpl);

const SESSION = {
  access_token: 'uuid|abc',
  access_token_expires_at: '2026-10-10T12:15:00.000+00:00',
  refresh_token: 'f'.repeat(64),
  refresh_token_expires_at: '2026-11-09T12:00:00.000+00:00',
  user: {
    id: '0199e2a0-7b1c-7000-8000-000000000001',
    email: 'ada@example.test',
    email_verified: false,
    display_name: 'Ada Lovelace',
    timezone: 'Africa/Nairobi',
    locale: 'en',
  },
};

const headers = (extra: Record<string, string> = {}): Record<string, string> => ({
  origin: 'https://tandem.test',
  host: 'tandem.test',
  'content-type': 'application/json',
  ...extra,
});

function post(path: string, body: unknown, extra: Record<string, string> = {}): Request {
  return new Request(`https://tandem.test${path}`, {
    method: 'POST',
    headers: headers(extra),
    body: JSON.stringify(body),
  });
}

beforeEach(() => {
  callApi.mockReset();
});

describe('loginHandler', () => {
  it('sets both cookies and returns the user with NO token in the body', async () => {
    callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION } });

    const response = await loginHandler(
      post('/api/auth/login', { email: 'ada@example.test', password: 'hunter2hunter2' }),
    );

    expect(response.status).toBe(200);
    const cookies = response.headers.getSetCookie();
    expect(cookies.some((c) => c.startsWith(`${ACCESS_COOKIE}=`))).toBe(true);
    expect(cookies.some((c) => c.startsWith(`${REFRESH_COOKIE}=`))).toBe(true);

    const body = await response.text();
    // Blueprint §91: browser JavaScript never holds a token. This assertion is the mechanical
    // guarantee of it - an added field that happened to include a token fails here.
    expect(body).not.toContain(SESSION.access_token);
    expect(body).not.toContain(SESSION.refresh_token);
    expect(JSON.parse(body)).toEqual({ user: SESSION.user });
  });

  it('sends an inert platform web and omits install_id', async () => {
    // ADR 0003: "the web BFF has no install_id concept to bind to". LoginController maps an
    // empty install_id to a NULL DeviceDescriptor, so the refresh row lands with device_id
    // NULL, which that ADR explicitly permits. If a web install_id is ever minted, the ADR
    // must be updated in the same change.
    //
    // `platform: 'web'` IS INERT TODAY, and this test's name says so rather than letting a
    // future reader treat it as load-bearing. MEASURED in LoginController: the descriptor is
    // `$installId === '' ? null : new DeviceDescriptor($installId, $request->string('platform',
    // 'web')->toString())` - so with no install_id the descriptor is null and `platform` is
    // NEVER READ. An earlier draft's comment said LoginController "defaults the platform to
    // 'web' when a descriptor exists", which is true and does not apply to the request being
    // asserted here.
    //
    // It is sent anyway, deliberately: it is the value this client would supply if a
    // descriptor ever existed, LoginRequest validates it (`sometimes|required|string|
    // in:ios,android,web`, measured), and declaring it means adding an install_id later is one
    // line rather than a decision. `src/session/types.ts`'s AuthApi docblock states the same
    // rule from the mobile side, where the field is deliberately NOT sent at all.
    //
    // Alternative considered and declined: drop `platform` so the body matches LoginSchema
    // exactly. Declined because the field and its enum then have to be rediscovered by
    // whoever adds the descriptor, and an inert field a test calls inert costs nothing.
    callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION } });
    await loginHandler(
      post('/api/auth/login', { email: 'ada@example.test', password: 'hunter2hunter2' }),
    );

    const sent = callApi.mock.calls[0]?.[1];
    expect(sent?.body).toEqual({
      email: 'ada@example.test',
      password: 'hunter2hunter2',
      platform: 'web',
    });
    expect(sent?.body).not.toHaveProperty('install_id');
  });

  it('strips a client-supplied install_id rather than relaying it (fix round 1, Important 2)', async () => {
    // The test above only ever sends a body that never HAD install_id, so it proves nothing
    // about stripping one that IS present. MEASURED in tandem-api's Login::rememberDevice
    // (app/Application/Auth/Login.php:98): an updateOrCreate on (user_id, install_id) that
    // writes platform and last_seen_at onto WHATEVER ROW THAT INSTALL_ID BELONGS TO - so a
    // same-origin web login carrying a real device's install_id overwrites that device's
    // row to platform: 'web', bumps its last_seen_at, and binds the web refresh chain to the
    // phone's device row, which ADR 0003 says the web BFF has no concept of.
    callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION } });
    await loginHandler(
      post('/api/auth/login', {
        email: 'ada@example.test',
        password: 'hunter2hunter2',
        install_id: 'attacker-chosen-device',
      }),
    );

    const sent = callApi.mock.calls[0]?.[1];
    expect(sent?.body).toEqual({
      email: 'ada@example.test',
      password: 'hunter2hunter2',
      platform: 'web',
    });
    expect(sent?.body).not.toHaveProperty('install_id');
  });

  it('passes a 401 through with its own message and sets no cookie', async () => {
    callApi.mockResolvedValue({
      kind: 'error',
      status: 401,
      envelope: { message: 'These credentials do not match our records.' },
      retryAfterSeconds: null,
    });

    const response = await loginHandler(
      post('/api/auth/login', { email: 'ada@example.test', password: 'x' }),
    );

    expect(response.status).toBe(401);
    expect(response.headers.getSetCookie()).toEqual([]);
    expect(await response.json()).toEqual({
      message: 'These credentials do not match our records.',
    });
  });

  it('passes a 429 through with Retry-After and no attempts counter', async () => {
    // §8.2: login's two limits count FAILED ATTEMPTS and a success clears the budget, so any
    // remaining-attempts number the UI shows is wrong the moment the user succeeds. The
    // handler forwards Retry-After and nothing else.
    callApi.mockResolvedValue({
      kind: 'error',
      status: 429,
      envelope: { message: 'Too Many Attempts.' },
      retryAfterSeconds: 540,
    });

    const response = await loginHandler(
      post('/api/auth/login', { email: 'ada@example.test', password: 'x' }),
    );

    expect(response.status).toBe(429);
    expect(response.headers.get('retry-after')).toBe('540');
  });

  it('answers a transport failure with the NETWORK house copy, not the server one (fix round 2, M5)', async () => {
    // HOUSE_NETWORK_MESSAGE was an exported, tested-nowhere, used-nowhere dead export - the
    // same shape fieldErrorsFor was in before round 1 wired it up. A transport failure never
    // reached the API at all, so it should read as "we couldn't reach Tandem", not "something
    // went wrong on our end".
    callApi.mockResolvedValue({ kind: 'transportFailure', error: new Error('ECONNREFUSED') });

    const response = await loginHandler(
      post('/api/auth/login', { email: 'ada@example.test', password: 'x' }),
    );

    expect(response.status).toBe(502);
    expect(await response.json()).toEqual({
      message: "We couldn't reach Tandem. Check your connection and try again.",
    });
  });

  it('rejects a 422 body before calling the API at all', async () => {
    const response = await loginHandler(
      post('/api/auth/login', { email: 'not-an-email', password: '' }),
    );

    expect(response.status).toBe(422);
    expect(callApi).not.toHaveBeenCalled();
    const body = await response.json();
    expect(body).toHaveProperty('errors');
  });

  it('refuses a cross-origin POST without calling the API', async () => {
    const response = await loginHandler(
      post(
        '/api/auth/login',
        { email: 'ada@example.test', password: 'hunter2hunter2' },
        {
          origin: 'https://evil.test',
        },
      ),
    );

    expect(response.status).toBe(403);
    expect(callApi).not.toHaveBeenCalled();
    expect(response.headers.getSetCookie()).toEqual([]);
  });
});

describe('registerHandler', () => {
  it('registers then logs in, because register returns no tokens', async () => {
    // POST /auth/register answers 201 with a UserResource and NO TOKENS. Confirmed in
    // openapi.json. So "create an account and be signed in" is two API calls, and this
    // handler owns the sequencing.
    callApi
      .mockResolvedValueOnce({ kind: 'ok', status: 201, json: { data: SESSION.user } })
      .mockResolvedValueOnce({ kind: 'ok', status: 200, json: { data: SESSION } });

    const response = await registerHandler(
      post('/api/auth/register', {
        email: 'ada@example.test',
        password: 'hunter2hunter2',
        display_name: 'Ada Lovelace',
        timezone: 'Africa/Nairobi',
      }),
    );

    expect(callApi).toHaveBeenCalledTimes(2);
    expect(callApi.mock.calls[0]?.[0]).toBe('/auth/register');
    expect(callApi.mock.calls[1]?.[0]).toBe('/auth/login');
    expect(response.status).toBe(201);
    expect(response.headers.getSetCookie()).toHaveLength(2);
  });

  it('reports registeredNotSignedIn when the follow-up login fails, rather than a dead end', async () => {
    callApi
      .mockResolvedValueOnce({ kind: 'ok', status: 201, json: { data: SESSION.user } })
      .mockResolvedValueOnce({
        kind: 'error',
        status: 429,
        envelope: { message: 'Too Many Attempts.' },
        retryAfterSeconds: 60,
      });

    const response = await registerHandler(
      post('/api/auth/register', {
        email: 'ada@example.test',
        password: 'hunter2hunter2',
        display_name: 'Ada Lovelace',
        timezone: 'Africa/Nairobi',
      }),
    );

    expect(response.status).toBe(201);
    expect(response.headers.getSetCookie()).toEqual([]);
    expect(await response.json()).toEqual({
      user: SESSION.user,
      signedIn: false,
      email: 'ada@example.test',
    });
  });

  it('does not call login when register fails', async () => {
    callApi.mockResolvedValueOnce({
      kind: 'error',
      status: 422,
      envelope: { message: 'The given data was invalid.', errors: { email: ['taken'] } },
      retryAfterSeconds: null,
    });

    const response = await registerHandler(
      post('/api/auth/register', {
        email: 'ada@example.test',
        password: 'hunter2hunter2',
        display_name: 'Ada Lovelace',
        timezone: 'Africa/Nairobi',
      }),
    );

    expect(callApi).toHaveBeenCalledTimes(1);
    expect(response.status).toBe(422);
    // Bare string, not ['taken'] - fix round 2, M1.
    expect(await response.json()).toMatchObject({ errors: { email: 'taken' } });
  });
});

describe('the `errors` field has ONE shape across every producer on this route (fix round 2, M1)', () => {
  // Reviewer's own measurement: on POST /api/auth/register, local validation produced
  // `errors: {email: ["must look like an email address"]}` (an array) while an API 422 -
  // reached through relayFailure, after Important 1 gated fieldErrorsFor this round -
  // produced `errors: {email: "has already been taken"}` (a bare string), with
  // resetPasswordHandler's then-intact 422 producing a THIRD, array-shaped answer. All three
  // producers of this field now agree: a bare string per field, first message wins.

  it('local (client-side) validation on register produces a bare string per field', async () => {
    const response = await registerHandler(
      post('/api/auth/register', {
        email: 'not-an-email',
        password: 'x',
        display_name: '',
        timezone: '',
      }),
    );
    expect(response.status).toBe(422);
    expect(callApi).not.toHaveBeenCalled();
    const body = (await response.json()) as { errors: Record<string, unknown> };
    expect(typeof body.errors.email).toBe('string');
  });

  it('a backend 422 on register produces a bare string per field', async () => {
    callApi.mockResolvedValueOnce({
      kind: 'error',
      status: 422,
      envelope: { message: 'The given data was invalid.', errors: { email: ['taken'] } },
      retryAfterSeconds: null,
    });
    const response = await registerHandler(
      post('/api/auth/register', {
        email: 'ada@example.test',
        password: 'hunter2hunter2',
        display_name: 'Ada Lovelace',
        timezone: 'Africa/Nairobi',
      }),
    );
    expect(response.status).toBe(422);
    const body = (await response.json()) as { errors: Record<string, unknown> };
    expect(typeof body.errors.email).toBe('string');
  });

  it('a backend 422 on reset-password produces a bare string per field', async () => {
    const resetBody = {
      token: 'a'.repeat(64),
      email: 'ada@example.test',
      password: 'hunter2hunter2',
    };
    const sentence = 'This password reset link is no longer valid. Request a new one.';
    callApi.mockResolvedValue({
      kind: 'error',
      status: 422,
      envelope: { message: sentence, errors: { token: [sentence] } },
      retryAfterSeconds: null,
    });
    const response = await resetPasswordHandler(post('/api/auth/reset-password', resetBody));
    expect(response.status).toBe(422);
    const body = (await response.json()) as { errors: Record<string, unknown> };
    expect(typeof body.errors.token).toBe('string');
  });
});

describe('refreshHandler', () => {
  it('re-sets BOTH cookies with Max-Age recomputed from the new expiries', async () => {
    // Every rotation issues a fresh 30-day refresh token (§9.1.1: a sliding window with no
    // absolute cap in Cycle 1). A handler that re-sets only the access cookie silently caps
    // the session at the FIRST refresh token's 30 days.
    callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION } });

    const response = await refreshHandler(
      new Request('https://tandem.test/api/auth/refresh', {
        method: 'POST',
        headers: headers({ cookie: `${REFRESH_COOKIE}=${'f'.repeat(64)}` }),
      }),
    );

    expect(response.status).toBe(200);
    const cookies = response.headers.getSetCookie();
    expect(cookies).toHaveLength(2);
    expect(cookies.some((c) => c.startsWith(`${REFRESH_COOKIE}=`))).toBe(true);
  });

  it('DELETES both cookies on a 401 and reports signed out, with nothing alarming', async () => {
    // bootstrap/app.php returns one identical 401 for unknown, expired, revoked AND replayed,
    // and RotationPolicy now maps Revoked to Reject - so this 401 is indistinguishable from
    // the user having logged out in another tab. The BFF must not infer theft.
    callApi.mockResolvedValue({
      kind: 'error',
      status: 401,
      envelope: { message: 'The refresh token is not valid.' },
      retryAfterSeconds: null,
    });

    const response = await refreshHandler(
      new Request('https://tandem.test/api/auth/refresh', {
        method: 'POST',
        headers: headers({ cookie: `${REFRESH_COOKIE}=${'f'.repeat(64)}` }),
      }),
    );

    expect(response.status).toBe(401);
    const cookies = response.headers.getSetCookie();
    expect(cookies).toHaveLength(2);
    expect(cookies.every((c) => c.includes('Max-Age=0'))).toBe(true);
    const body = await response.json();
    expect(body).toEqual({ signedIn: false });
    expect(JSON.stringify(body).toLowerCase()).not.toContain('compromis');
  });

  it('answers 401 with no API call when there is no refresh cookie', async () => {
    const response = await refreshHandler(
      new Request('https://tandem.test/api/auth/refresh', { method: 'POST', headers: headers() }),
    );
    expect(response.status).toBe(401);
    expect(callApi).not.toHaveBeenCalled();
  });

  it('answers 401 without calling the API when the cookie is not 64 characters', async () => {
    // RefreshTokenRequest pins refresh_token to exactly 64 characters, so a truncated cookie
    // is a 422 upstream - a pointless round trip for a value that can never succeed.
    const response = await refreshHandler(
      new Request('https://tandem.test/api/auth/refresh', {
        method: 'POST',
        headers: headers({ cookie: `${REFRESH_COOKIE}=short` }),
      }),
    );
    expect(response.status).toBe(401);
    expect(callApi).not.toHaveBeenCalled();
    expect(response.headers.getSetCookie()).toHaveLength(2);
  });

  it.each([500, 503, 429])(
    'does NOT clear cookies on a non-401 error status (%i) - only 401 is terminal (fix round 1, Important 3)',
    async (status) => {
      // The handler's own docblock argues a transportFailure "is never evidence the
      // credential is bad, so cookies are left untouched" - a 500/503/429 from the API is the
      // IDENTICAL situation (the refresh token itself was never examined) and, before this
      // fix, got the opposite treatment: EVERY non-ok result cleared both cookies.
      callApi.mockResolvedValue({
        kind: 'error',
        status,
        envelope: { message: 'Service Unavailable' },
        retryAfterSeconds: null,
      });

      const response = await refreshHandler(
        new Request('https://tandem.test/api/auth/refresh', {
          method: 'POST',
          headers: headers({ cookie: `${REFRESH_COOKIE}=${'f'.repeat(64)}` }),
        }),
      );

      expect(response.headers.getSetCookie()).toEqual([]);
    },
  );

  it('forwards Retry-After on a 429 (fix round 2, M4)', async () => {
    // The non-401 branch built a bare jsonResponse(status, {signedIn: false}) with no
    // headers argument at all, so a 429's Retry-After - the one header the browser's
    // single-flight caller would actually want to back off on, on the one endpoint whose
    // rate limit matters most - never reached it.
    callApi.mockResolvedValue({
      kind: 'error',
      status: 429,
      envelope: { message: 'Too Many Attempts.' },
      retryAfterSeconds: 30,
    });

    const response = await refreshHandler(
      new Request('https://tandem.test/api/auth/refresh', {
        method: 'POST',
        headers: headers({ cookie: `${REFRESH_COOKIE}=${'f'.repeat(64)}` }),
      }),
    );

    expect(response.status).toBe(429);
    expect(response.headers.get('retry-after')).toBe('30');
  });

  it('does NOT clear cookies on a transport failure either', async () => {
    callApi.mockResolvedValue({ kind: 'transportFailure', error: new Error('ECONNREFUSED') });

    const response = await refreshHandler(
      new Request('https://tandem.test/api/auth/refresh', {
        method: 'POST',
        headers: headers({ cookie: `${REFRESH_COOKIE}=${'f'.repeat(64)}` }),
      }),
    );

    expect(response.headers.getSetCookie()).toEqual([]);
  });

  it('does not throw and answers 401 without calling the API on a malformed refresh cookie (fix round 1, Important 6)', async () => {
    // Entry point 3. A malformed refresh cookie decodes to null, which is indistinguishable
    // from "absent" here - same short-circuit as the "no cookie at all" / "wrong length"
    // cases above, and for the same reason: no value that could ever succeed upstream.
    const response = await refreshHandler(
      new Request('https://tandem.test/api/auth/refresh', {
        method: 'POST',
        headers: headers({ cookie: `${REFRESH_COOKIE}=%` }),
      }),
    );
    expect(response.status).toBe(401);
    expect(callApi).not.toHaveBeenCalled();
    expect(response.headers.getSetCookie()).toHaveLength(2);
  });
});

describe('logoutHandler', () => {
  it('clears both cookies even when the API answers 401', async () => {
    // The access token may have expired while the page sat open. Leaving cookies behind
    // after a failed logout leaves the user looking signed in.
    callApi.mockResolvedValue({
      kind: 'error',
      status: 401,
      envelope: { message: 'Unauthenticated.' },
      retryAfterSeconds: null,
    });

    const response = await logoutHandler(
      new Request('https://tandem.test/api/auth/logout', {
        method: 'POST',
        headers: headers({
          cookie: `${ACCESS_COOKIE}=uuid%7Cabc; ${REFRESH_COOKIE}=${'f'.repeat(64)}`,
        }),
      }),
    );

    expect(response.status).toBe(204);
    const cookies = response.headers.getSetCookie();
    // toHaveLength(2) FIRST: Array.prototype.every on an empty array returns true
    // vacuously, regardless of the predicate. MEASURED: without this line, a handler that
    // returns zero Set-Cookie headers on a 401 (i.e. clears NOTHING) still passes the
    // `.every(...)` assertion below, because `[].every(...)` is `true` - the exact failure
    // mode this task's own instructions warn about ("an enumerated list needs its liveness
    // half"). `refreshHandler`'s sibling 401 test two describe blocks up already includes
    // this check; it was missing here.
    expect(cookies).toHaveLength(2);
    expect(cookies.every((c) => c.includes('Max-Age=0'))).toBe(true);
  });

  it('clears both cookies without an API call when there is no session', async () => {
    const response = await logoutHandler(
      new Request('https://tandem.test/api/auth/logout', { method: 'POST', headers: headers() }),
    );
    expect(response.status).toBe(204);
    expect(callApi).not.toHaveBeenCalled();
    expect(response.headers.getSetCookie()).toHaveLength(2);
  });

  it('never refreshes in order to obtain a bearer', async () => {
    callApi.mockResolvedValue({ kind: 'ok', status: 204, json: null });
    await logoutHandler(
      new Request('https://tandem.test/api/auth/logout', {
        method: 'POST',
        headers: headers({
          cookie: `${ACCESS_COOKIE}=uuid%7Cabc; ${REFRESH_COOKIE}=${'f'.repeat(64)}`,
        }),
      }),
    );
    expect(callApi).toHaveBeenCalledTimes(1);
    expect(callApi.mock.calls[0]?.[0]).toBe('/auth/logout');
  });

  it('does not throw and still clears both cookies on a malformed access cookie (fix round 1, Important 6)', async () => {
    // Entry point 2. This is the one the reviewer called out specifically: because logout
    // itself threw before this fix, the app had NO WAY to clear a malformed cookie - a user
    // stuck with one needed to clear it through their browser's own settings.
    const response = await logoutHandler(
      new Request('https://tandem.test/api/auth/logout', {
        method: 'POST',
        headers: headers({ cookie: `${ACCESS_COOKIE}=%; ${REFRESH_COOKIE}=${'f'.repeat(64)}` }),
      }),
    );
    expect(response.status).toBe(204);
    expect(response.headers.getSetCookie()).toHaveLength(2);
    expect(callApi).not.toHaveBeenCalled();
  });
});

describe('verifyEmailHandler', () => {
  it('relays the raw query byte-for-byte and sends no body', async () => {
    // UrlGenerator::hasCorrectSignature rebuilds the signed original from the raw QUERY_STRING
    // in arrival order. Measured: URLSearchParams re-serialisation turns '%20' into '+',
    // '~' into '%7E' and '%2A' into '*' - each a permanent 403.
    const raw =
      'expires=1791635696&id=0199e2a0-7b1c-7000-8000-000000000001&hash=ab%2Fcd&signature=ZZ%3D%3D&pad=a~b';
    callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION.user } });

    const response = await verifyEmailHandler(
      new Request(`https://tandem.test/api/auth/verify-email?${raw}`, {
        method: 'POST',
        headers: headers(),
      }),
    );

    expect(response.status).toBe(200);
    expect(callApi.mock.calls[0]?.[0]).toBe(`/auth/verify-email?${raw}`);
    expect(callApi.mock.calls[0]?.[1]?.body).toBeUndefined();
  });

  it('passes a 403 through as one unexplained answer', async () => {
    // One answer for a tampered signature, an expired link, an unknown id and a hash that no
    // longer matches the current address. 403 means "this link is not usable" and never "no
    // such account".
    callApi.mockResolvedValue({
      kind: 'error',
      status: 403,
      envelope: { message: 'This action is unauthorized.' },
      retryAfterSeconds: null,
    });

    const response = await verifyEmailHandler(
      new Request('https://tandem.test/api/auth/verify-email?id=x', {
        method: 'POST',
        headers: headers(),
      }),
    );
    expect(response.status).toBe(403);
  });
});

describe('sessionHandler', () => {
  it('returns the user with no token when the access cookie is live', async () => {
    callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION.user } });

    const response = await sessionHandler(
      new Request('https://tandem.test/api/auth/session', {
        headers: { cookie: `${ACCESS_COOKIE}=uuid%7Cabc` },
      }),
    );

    // clone() BEFORE the first read: a Response's body can only be consumed once, and
    // calling clone() after response.json() has already read it throws "Body has already
    // been consumed" (measured against the installed Node 22's Fetch implementation) - a
    // defect in this test as originally given, not in the handler under test. Cloning first
    // preserves the same two assertions without disturbing either stream.
    const clonedForRawCheck = response.clone();
    expect(await response.json()).toEqual({ status: 'signedIn', user: SESSION.user });
    expect(await clonedForRawCheck.text()).not.toContain('uuid|abc');
  });

  it('reports expired - not signedOut - on a 401, so the client can refresh', async () => {
    callApi.mockResolvedValue({
      kind: 'error',
      status: 401,
      envelope: { message: 'Unauthenticated.' },
      retryAfterSeconds: null,
    });

    const response = await sessionHandler(
      new Request('https://tandem.test/api/auth/session', {
        headers: { cookie: `${ACCESS_COOKIE}=uuid%7Cabc` },
      }),
    );
    expect(await response.json()).toEqual({ status: 'expired' });
  });

  it('reports signedOut with no API call when there is no cookie at all', async () => {
    const response = await sessionHandler(new Request('https://tandem.test/api/auth/session'));
    expect(await response.json()).toEqual({ status: 'signedOut' });
    expect(callApi).not.toHaveBeenCalled();
  });

  it('does not throw and reports signedOut on a malformed access cookie (fix round 1, Important 6)', async () => {
    // Entry point 1 of the three this handler-level sweep covers (see cookies.test.ts for
    // the root-cause fix in readSessionCookies itself).
    const response = await sessionHandler(
      new Request('https://tandem.test/api/auth/session', {
        headers: { cookie: `${ACCESS_COOKIE}=%` },
      }),
    );
    expect(await response.json()).toEqual({ status: 'signedOut' });
    expect(callApi).not.toHaveBeenCalled();
  });

  it.each([500, 503, 429])(
    'does NOT report expired for a non-401 error status (%i) - fix round 1, Important 3',
    async (status) => {
      // `expired` is the one signal useSession.ts acts on by calling refreshSession(). Before
      // this fix, ANY non-ok result (including a 5xx that has nothing to do with the access
      // token) mapped to `expired`, so a brief API outage walked every open web session
      // through expired -> refresh -> (the API still down) -> refreshHandler's OWN
      // over-eager clearing -> both credentials discarded -> signed out, forcing a password
      // re-entry for a refresh token that was never invalid.
      callApi.mockResolvedValue({
        kind: 'error',
        status,
        envelope: { message: 'Service Unavailable' },
        retryAfterSeconds: null,
      });

      const response = await sessionHandler(
        new Request('https://tandem.test/api/auth/session', {
          headers: { cookie: `${ACCESS_COOKIE}=uuid%7Cabc` },
        }),
      );

      const body: unknown = await response.json();
      expect(body).not.toEqual({ status: 'expired' });
    },
  );

  it('does NOT report expired on a transport failure either', async () => {
    callApi.mockResolvedValue({ kind: 'transportFailure', error: new Error('ECONNREFUSED') });

    const response = await sessionHandler(
      new Request('https://tandem.test/api/auth/session', {
        headers: { cookie: `${ACCESS_COOKIE}=uuid%7Cabc` },
      }),
    );

    const body: unknown = await response.json();
    expect(body).not.toEqual({ status: 'expired' });
  });
});

// Everything below is supplementary to the brief's given suite. The brief's handlers.test.ts
// never imports forgotPasswordHandler or resetPasswordHandler at all - two of the eight route
// handlers go completely unexercised - and tests CSRF rejection on exactly one of the seven
// mutating handlers (loginHandler), leaving the other six's assertSameOrigin call unproven.
// Both are exactly the "enumerated list with no liveness half" shape the task's own
// instructions warn recurred eight times in Task 6.

describe('forgotPasswordHandler', () => {
  it('answers 202 with an empty body on success and writes no cookie', async () => {
    callApi.mockResolvedValue({ kind: 'ok', status: 202, json: null });

    const response = await forgotPasswordHandler(
      post('/api/auth/forgot-password', { email: 'ada@example.test' }),
    );

    expect(response.status).toBe(202);
    expect(response.headers.getSetCookie()).toEqual([]);
    expect(await response.text()).toBe('');
  });

  it('never parses a body and never implies whether the account exists, win or lose', async () => {
    callApi.mockResolvedValue({ kind: 'ok', status: 202, json: null });
    await forgotPasswordHandler(post('/api/auth/forgot-password', { email: 'ada@example.test' }));
    expect(callApi.mock.calls[0]?.[0]).toBe('/auth/forgot-password');
    expect(callApi.mock.calls[0]?.[1]?.body).toEqual({ email: 'ada@example.test' });
  });

  it('forwards a 429 Retry-After, §8.2 (3/hour/email)', async () => {
    callApi.mockResolvedValue({
      kind: 'error',
      status: 429,
      envelope: { message: 'Too Many Attempts.' },
      retryAfterSeconds: 1800,
    });

    const response = await forgotPasswordHandler(
      post('/api/auth/forgot-password', { email: 'ada@example.test' }),
    );

    expect(response.status).toBe(429);
    expect(response.headers.get('retry-after')).toBe('1800');
  });

  it('rejects an invalid email before calling the API', async () => {
    const response = await forgotPasswordHandler(
      post('/api/auth/forgot-password', { email: 'not-an-email' }),
    );
    expect(response.status).toBe(422);
    expect(callApi).not.toHaveBeenCalled();
  });

  it('refuses a cross-origin POST without calling the API', async () => {
    const response = await forgotPasswordHandler(
      post(
        '/api/auth/forgot-password',
        { email: 'ada@example.test' },
        { origin: 'https://evil.test' },
      ),
    );
    expect(response.status).toBe(403);
    expect(callApi).not.toHaveBeenCalled();
  });
});

describe('resetPasswordHandler', () => {
  const resetBody = {
    token: 'a'.repeat(64),
    email: 'ada@example.test',
    password: 'hunter2hunter2',
  };

  it('clears both cookies and answers 204 on success - the API revoked every token server-side', async () => {
    callApi.mockResolvedValue({ kind: 'ok', status: 204, json: null });

    const response = await resetPasswordHandler(post('/api/auth/reset-password', resetBody));

    expect(response.status).toBe(204);
    const cookies = response.headers.getSetCookie();
    expect(cookies).toHaveLength(2);
    expect(cookies.every((c) => c.includes('Max-Age=0'))).toBe(true);
  });

  it('forwards a 422 through relayFailure, so errors.token carries the server sentence (fix round 2, M1: bare string, not an array)', async () => {
    const sentence = 'This password reset link is no longer valid. Request a new one.';
    callApi.mockResolvedValue({
      kind: 'error',
      status: 422,
      envelope: { message: sentence, errors: { token: [sentence] } },
      retryAfterSeconds: null,
    });

    const response = await resetPasswordHandler(post('/api/auth/reset-password', resetBody));

    expect(response.status).toBe(422);
    expect(response.headers.getSetCookie()).toEqual([]);
    // Bare string, not `[sentence]` - the unified shape M1 asks for, not the old intact
    // array passthrough.
    expect(await response.json()).toEqual({ message: sentence, errors: { token: sentence } });
  });

  it('rejects a malformed body (token not 64 characters) before calling the API', async () => {
    const response = await resetPasswordHandler(
      post('/api/auth/reset-password', { ...resetBody, token: 'short' }),
    );
    expect(response.status).toBe(422);
    expect(callApi).not.toHaveBeenCalled();
  });

  it('refuses a cross-origin POST without calling the API', async () => {
    const response = await resetPasswordHandler(
      post('/api/auth/reset-password', resetBody, { origin: 'https://evil.test' }),
    );
    expect(response.status).toBe(403);
    expect(callApi).not.toHaveBeenCalled();
    expect(response.headers.getSetCookie()).toEqual([]);
  });

  it('substitutes house copy for a 500 rather than relaying the raw backend message (§135)', async () => {
    // Fix round 1, Important 1: `result.envelope ?? …` forwarded the envelope INTACT at every
    // status, not just 422. ErrorEnvelopeSchema is a non-strict z.object, so a Laravel
    // debug-mode 500 carrying exception text parses as a valid envelope and relayed verbatim.
    const rawExceptionText =
      'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key ' +
      '"users_email_unique"';
    callApi.mockResolvedValue({
      kind: 'error',
      status: 500,
      envelope: { message: rawExceptionText },
      retryAfterSeconds: null,
    });

    const response = await resetPasswordHandler(post('/api/auth/reset-password', resetBody));

    const body = await response.json();
    expect(JSON.stringify(body)).not.toContain('SQLSTATE');
    expect(JSON.stringify(body)).not.toContain(rawExceptionText);
  });

  it('does not relay raw backend text through `errors` either, at a 500 (fix round 2, Important 1 reopened)', async () => {
    // Round 1 gated `message` through userMessageFor's allowlist but left `errors` relayed
    // via fieldErrorsFor with NO status gate at all - the identical shape, one field over.
    // MEASURED by the reviewer at handlers.ts:150: RESET /auth/reset-password 500 with an
    // envelope carrying `errors.token` relayed that raw text verbatim.
    const rawErrorText = 'RAW-BACKEND-TEXT-500 SQLSTATE[23000]';
    callApi.mockResolvedValue({
      kind: 'error',
      status: 500,
      envelope: {
        message: 'Something went wrong on our end. Please try again.',
        errors: { token: [rawErrorText] },
      },
      retryAfterSeconds: null,
    });

    const response = await resetPasswordHandler(post('/api/auth/reset-password', resetBody));

    const body = await response.json();
    expect(JSON.stringify(body)).not.toContain('RAW-BACKEND-TEXT');
    expect(JSON.stringify(body)).not.toContain(rawErrorText);
  });
});

// Shared by both sweeps below (CSRF liveness and the §91 token-leak liveness). Every handler
// this file knows about, by NAME for building per-handler fixtures and by REFERENCE for the
// completeness checks that must not be fooled by what a function happens to be called.
const ALL_HANDLER_NAMES = [
  'loginHandler',
  'registerHandler',
  'refreshHandler',
  'logoutHandler',
  'forgotPasswordHandler',
  'resetPasswordHandler',
  'verifyEmailHandler',
  'sessionHandler',
] as const;

const MUTATING_HANDLER_NAMES = ALL_HANDLER_NAMES.filter((name) => name !== 'sessionHandler');

const HANDLERS_BY_NAME: Record<(typeof ALL_HANDLER_NAMES)[number], unknown> = {
  loginHandler,
  registerHandler,
  refreshHandler,
  logoutHandler,
  forgotPasswordHandler,
  resetPasswordHandler,
  verifyEmailHandler,
  sessionHandler,
};

/**
 * FIX ROUND 1, MINOR ("naming-convention hole"): this used to filter
 * `Object.keys(handlersModule)` by a `name.endsWith('Handler')` string test, in the CSRF
 * sweep only. PROVEN: a ninth exported handler named `deleteAccount` (no CSRF guard at all)
 * left the whole suite 38/38 green, because the name-suffix filter never saw it - it decided
 * what counts as "a handler" by spelling, which a later contributor can opt out of by
 * accident. This collects every function-valued export BY REFERENCE instead, with no name
 * test anywhere, so it is immune to what a new handler happens to be called. Used by both
 * sweeps below, so the fix does not have to be made (or missed) twice.
 */
function assertExportsAreExactly(expected: ReadonlySet<unknown>): void {
  const exportedFunctions = new Set(
    Object.values(handlersModule).filter((value) => typeof value === 'function'),
  );
  expect(exportedFunctions).toEqual(expected);
}

describe('CSRF guards every mutating handler, not just loginHandler', () => {
  // The LIVENESS half: this is the complete set of mutating handlers handlers.ts exports
  // right now, read off the module rather than retyped from memory, so a ninth handler added
  // later without a row below fails this assertion instead of silently going unchecked.
  it('is the complete set this module exports, aside from the one GET (sessionHandler)', () => {
    assertExportsAreExactly(new Set(ALL_HANDLER_NAMES.map((name) => HANDLERS_BY_NAME[name])));
  });

  // FIX ROUND 1: swept for a THIRD instance of the same shape after closing the CSRF and
  // §91 completeness checks above, per the reviewer's own warning that this pattern recurred
  // eight times in one task. Found one: this was `Record<string, () => Request>`, a loose
  // string-keyed type that gives no COMPILE-TIME guarantee a new mutating handler's name
  // gets an entry here - only the runtime `if (buildRequest === undefined) throw` below
  // caught it, and only once the suite actually ran. Typed against the literal union instead
  // (matching `successScenarioFor` below, which already had this for free from its own
  // declaration), a missing entry is now a build-time error, not a runtime one.
  const requestFor: Record<(typeof MUTATING_HANDLER_NAMES)[number], () => Request> = {
    loginHandler: () =>
      post(
        '/api/auth/login',
        { email: 'ada@example.test', password: 'hunter2hunter2' },
        {
          origin: 'https://evil.test',
        },
      ),
    registerHandler: () =>
      post(
        '/api/auth/register',
        {
          email: 'ada@example.test',
          password: 'hunter2hunter2',
          display_name: 'Ada',
          timezone: 'Africa/Nairobi',
        },
        { origin: 'https://evil.test' },
      ),
    refreshHandler: () =>
      new Request('https://tandem.test/api/auth/refresh', {
        method: 'POST',
        headers: headers({
          origin: 'https://evil.test',
          cookie: `${REFRESH_COOKIE}=${'f'.repeat(64)}`,
        }),
      }),
    logoutHandler: () =>
      new Request('https://tandem.test/api/auth/logout', {
        method: 'POST',
        headers: headers({
          origin: 'https://evil.test',
          cookie: `${ACCESS_COOKIE}=uuid%7Cabc; ${REFRESH_COOKIE}=${'f'.repeat(64)}`,
        }),
      }),
    forgotPasswordHandler: () =>
      post(
        '/api/auth/forgot-password',
        { email: 'ada@example.test' },
        { origin: 'https://evil.test' },
      ),
    resetPasswordHandler: () =>
      post(
        '/api/auth/reset-password',
        { token: 'a'.repeat(64), email: 'ada@example.test', password: 'hunter2hunter2' },
        { origin: 'https://evil.test' },
      ),
    verifyEmailHandler: () =>
      new Request('https://tandem.test/api/auth/verify-email?id=x', {
        method: 'POST',
        headers: headers({ origin: 'https://evil.test' }),
      }),
  };

  it.each(MUTATING_HANDLER_NAMES)(
    '%s refuses a cross-origin POST without calling the API',
    async (name) => {
      const handler = handlersModule[name as keyof typeof handlersModule];
      const buildRequest = requestFor[name];
      if (buildRequest === undefined)
        throw new Error(`no cross-origin request fixture for ${name}`);

      const response = await handler(buildRequest());

      expect(response.status).toBe(403);
      expect(callApi).not.toHaveBeenCalled();
      expect(response.headers.getSetCookie()).toEqual([]);
    },
  );
});

/**
 * FIX ROUND 1, IMPORTANT 4: the §91 token-leak guard
 * ("sets both cookies and returns the user with NO token in the body") previously covered
 * loginHandler alone. PROVEN: planting `access_token` in registerHandler's 201 body AND
 * `refresh_token` in refreshHandler's 200 body left the suite at 7 files / 91 passed - the
 * exact one-of-N shape this file already diagnosed and fixed for CSRF (Important/Minor
 * above), left standing on the single invariant this whole task exists to deliver.
 *
 * Closed the same way: an export-driven sweep over EVERY handler (sessionHandler included -
 * this is the one sweep in this file with no GET exemption, since a GET's body can leak a
 * token exactly as easily as a POST's), each driven to its own success path, each checked
 * for either token in its response text.
 */
describe('no handler ever puts a token in a success body (§91), not just loginHandler', () => {
  it('is the complete set this module exports - no handler is exempt from this one', () => {
    assertExportsAreExactly(new Set(ALL_HANDLER_NAMES.map((name) => HANDLERS_BY_NAME[name])));
  });

  const successScenarioFor: Record<
    (typeof ALL_HANDLER_NAMES)[number],
    { mock: () => void; request: () => Request }
  > = {
    loginHandler: {
      mock: () => callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION } }),
      request: () =>
        post('/api/auth/login', { email: 'ada@example.test', password: 'hunter2hunter2' }),
    },
    registerHandler: {
      mock: () =>
        callApi
          .mockResolvedValueOnce({ kind: 'ok', status: 201, json: { data: SESSION.user } })
          .mockResolvedValueOnce({ kind: 'ok', status: 200, json: { data: SESSION } }),
      request: () =>
        post('/api/auth/register', {
          email: 'ada@example.test',
          password: 'hunter2hunter2',
          display_name: 'Ada Lovelace',
          timezone: 'Africa/Nairobi',
        }),
    },
    refreshHandler: {
      mock: () => callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION } }),
      request: () =>
        new Request('https://tandem.test/api/auth/refresh', {
          method: 'POST',
          headers: headers({ cookie: `${REFRESH_COOKIE}=${'f'.repeat(64)}` }),
        }),
    },
    logoutHandler: {
      mock: () => callApi.mockResolvedValue({ kind: 'ok', status: 204, json: null }),
      request: () =>
        new Request('https://tandem.test/api/auth/logout', {
          method: 'POST',
          headers: headers({
            cookie: `${ACCESS_COOKIE}=uuid%7Cabc; ${REFRESH_COOKIE}=${'f'.repeat(64)}`,
          }),
        }),
    },
    forgotPasswordHandler: {
      mock: () => callApi.mockResolvedValue({ kind: 'ok', status: 202, json: null }),
      request: () => post('/api/auth/forgot-password', { email: 'ada@example.test' }),
    },
    resetPasswordHandler: {
      mock: () => callApi.mockResolvedValue({ kind: 'ok', status: 204, json: null }),
      request: () =>
        post('/api/auth/reset-password', {
          token: 'a'.repeat(64),
          email: 'ada@example.test',
          password: 'hunter2hunter2',
        }),
    },
    verifyEmailHandler: {
      mock: () =>
        callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION.user } }),
      request: () =>
        new Request('https://tandem.test/api/auth/verify-email?id=x', {
          method: 'POST',
          headers: headers(),
        }),
    },
    sessionHandler: {
      mock: () =>
        callApi.mockResolvedValue({ kind: 'ok', status: 200, json: { data: SESSION.user } }),
      request: () =>
        new Request('https://tandem.test/api/auth/session', {
          headers: { cookie: `${ACCESS_COOKIE}=uuid%7Cabc` },
        }),
    },
  };

  it.each(ALL_HANDLER_NAMES)('%s never puts a token in its success body', async (name) => {
    const handler = handlersModule[name as keyof typeof handlersModule];
    successScenarioFor[name].mock();

    const response = await handler(successScenarioFor[name].request());
    const text = await response.text();

    expect(text).not.toContain(SESSION.access_token);
    expect(text).not.toContain(SESSION.refresh_token);
  });
});
