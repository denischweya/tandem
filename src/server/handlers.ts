import { z } from 'zod';
import {
  ForgotPasswordSchema,
  LoginSchema,
  LogoutSchema,
  RefreshSchema,
  RegisterSchema,
  ResetPasswordSchema,
  SessionSchema,
  UserSchema,
} from '@tandem/shared';

import { callApi, type ApiCallResult } from './apiFetch';
import { clearedSetCookies, readSessionCookies, sessionSetCookies } from './cookies';
import { assertSameOrigin } from './csrf';
import { fieldErrorsFor, HOUSE_SERVER_MESSAGE, userMessageFor } from './errors';

/**
 * Eight route handlers, each `(request: Request) => Promise<Response>` and NONE of them
 * calling `next/headers`'s `cookies()` - see this module's test file for why: `cookies()`'s
 * write path throws outside a Server Action or Route Handler's request scope, so a handler
 * built on it could only be tested through a mock of the framework rather than a test of the
 * handler. Reading the cookie header off `request` and writing `Set-Cookie` with
 * `Headers.append` keeps every handler a plain function of its input.
 *
 * Each of the seven MUTATING handlers (every one but `sessionHandler`, the lone GET) runs
 * `assertSameOrigin` first and returns a bare 403 on failure, before validating a body or
 * touching `callApi` - ADR 0004's CSRF requirement, enforced once per handler rather than once
 * per router the way middleware would hide it.
 */

function jsonResponse(
  status: number,
  body: unknown,
  extraHeaders?: Record<string, string>,
): Response {
  const headers = new Headers({ 'content-type': 'application/json' });
  for (const [name, value] of Object.entries(extraHeaders ?? {})) headers.set(name, value);
  return new Response(JSON.stringify(body), { status, headers });
}

function noBodyResponse(status: number): Response {
  return new Response(null, { status });
}

/** 403, no body worth parsing, no cookie write. The one response every mutating handler
 *  shares for a rejected Origin. */
function csrfResponse(): Response {
  return jsonResponse(403, { message: 'Cross-origin request refused.' });
}

/** `assertSameOrigin` only ever throws `CsrfError` (see csrf.ts) - this turns that one throw
 *  into the one response, so each handler reads as a single early-return rather than a
 *  try/catch of its own. */
function csrfGuard(request: Request): Response | null {
  try {
    assertSameOrigin(request);
    return null;
  } catch {
    return csrfResponse();
  }
}

function withClearedCookies(response: Response): Response {
  const [access, refresh] = clearedSetCookies();
  response.headers.append('set-cookie', access);
  response.headers.append('set-cookie', refresh);
  return response;
}

function withSetCookies(
  response: Response,
  session: z.infer<typeof SessionSchema>,
  nowMs: number,
): Response {
  const [access, refresh] = sessionSetCookies(session, nowMs);
  response.headers.append('set-cookie', access);
  response.headers.append('set-cookie', refresh);
  return response;
}

function zodFieldErrors(error: z.ZodError): Record<string, string[]> {
  const out: Record<string, string[]> = {};
  for (const issue of error.issues) {
    const key = issue.path.length > 0 ? issue.path.join('.') : '_';
    (out[key] ??= []).push(issue.message);
  }
  return out;
}

/**
 * Validates the request body against `schema`, BEFORE any call to `callApi` - the "reject
 * before calling the API" half of every mutating handler's five steps. An unparsable body
 * (not JSON at all, or no body) is treated exactly like one that fails the schema: `raw` falls
 * back to `null`, which fails every schema here the same way an empty object would.
 */
async function validateBody<T>(
  request: Request,
  schema: z.ZodType<T>,
): Promise<{ ok: true; data: T } | { ok: false; response: Response }> {
  let raw: unknown = null;
  try {
    raw = await request.json();
  } catch {
    raw = null;
  }
  const parsed = schema.safeParse(raw);
  if (parsed.success) return { ok: true, data: parsed.data };
  return {
    ok: false,
    response: jsonResponse(422, {
      message: 'The given data was invalid.',
      errors: zodFieldErrors(parsed.error),
    }),
  };
}

/**
 * Parses a 2xx body's `{ data: … }` envelope against `schema`. `null` on a mismatch - the
 * contract and the server disagree - which every call site turns into house copy rather than
 * letting a raw `ZodError` escape to the browser (blueprint §135).
 */
function parseEnvelopeData<T>(json: unknown, schema: z.ZodType<T>): T | null {
  const parsed = z.object({ data: schema }).safeParse(json);
  return parsed.success ? parsed.data.data : null;
}

/**
 * The shared error relay for every handler EXCEPT `resetPasswordHandler` (which forwards its
 * envelope intact - see that handler's own comment for why). Forwards `Retry-After` whenever
 * the API set one and nothing else; the message is `userMessageFor`'s authored-or-house-copy
 * choice, never the raw body, so an unauthored 4xx or a 5xx can never put backend text in
 * front of a user.
 */
function relayFailure(result: ApiCallResult): Response {
  if (result.kind === 'ok') {
    // Unreachable by construction - every call site only passes a non-'ok' result - but kept
    // exhaustive rather than asserted away, so a mis-threaded 'ok' gets a real response
    // instead of a thrown error reaching the browser.
    return jsonResponse(502, { message: HOUSE_SERVER_MESSAGE });
  }
  if (result.kind === 'transportFailure') {
    return jsonResponse(502, { message: HOUSE_SERVER_MESSAGE });
  }
  const headers: Record<string, string> = {};
  if (result.retryAfterSeconds !== null) headers['retry-after'] = String(result.retryAfterSeconds);
  const body: { message: string; errors?: Record<string, string> } = {
    message: userMessageFor(result.status, result.envelope),
  };
  const fieldErrors = fieldErrorsFor(result.envelope);
  if (fieldErrors !== null) body.errors = fieldErrors;
  return jsonResponse(result.status, body, headers);
}

/**
 * Validates with `LoginSchema`, sends `{email, password, platform: 'web'}` with deliberately
 * NO `install_id` (ADR 0003: "the web BFF has no install_id concept to bind to" - see
 * `handlers.test.ts`'s own test name for why `platform` is sent anyway while being inert
 * today), parses the response with `SessionSchema`, sets both cookies, and returns `{user}`
 * with no token in the body - the mechanical form of blueprint §91.
 *
 * FIX ROUND 1, IMPORTANT 2: the body is built EXPLICITLY, field by field, rather than spread
 * from `validated.data` - `{...validated.data, platform: 'web'}` relayed a client-supplied
 * `install_id` straight through, because `LoginSchema` carries it as optional and a same-
 * origin request can set any body field it likes. MEASURED in tandem-api's
 * `Login::rememberDevice`: an `updateOrCreate` on `(user_id, install_id)` that writes
 * `platform`/`last_seen_at` onto whatever device row that `install_id` belongs to - so this
 * silently rebound a real device's row to `platform: 'web'` and bound the web refresh chain
 * to it, a thing ADR 0003 says the web BFF has no concept of. `registerHandler`'s login
 * follow-up already built its body this same explicit way; this brings `loginHandler` into
 * line with it rather than the other way around.
 */
export async function loginHandler(request: Request): Promise<Response> {
  const csrfFailure = csrfGuard(request);
  if (csrfFailure !== null) return csrfFailure;

  const validated = await validateBody(request, LoginSchema);
  if (!validated.ok) return validated.response;

  const result = await callApi('/auth/login', {
    method: 'POST',
    body: { email: validated.data.email, password: validated.data.password, platform: 'web' },
  });

  if (result.kind !== 'ok') return relayFailure(result);

  const session = parseEnvelopeData(result.json, SessionSchema);
  if (session === null) return jsonResponse(502, { message: HOUSE_SERVER_MESSAGE });

  return withSetCookies(jsonResponse(200, { user: session.user }), session, Date.now());
}

/**
 * Validates with `RegisterSchema`, calls `/auth/register` (201, no tokens - confirmed in
 * openapi.json), then `/auth/login` with the same credentials: "create an account and be
 * signed in" is two API calls, and this handler owns the sequencing. On a login failure (or a
 * contract mismatch in its response) it returns 201 with `{user, signedIn: false, email}` and
 * NO cookies - a registered-but-not-signed-in user is a handled state, not a dead end. On a
 * register failure it never calls login at all.
 */
export async function registerHandler(request: Request): Promise<Response> {
  const csrfFailure = csrfGuard(request);
  if (csrfFailure !== null) return csrfFailure;

  const validated = await validateBody(request, RegisterSchema);
  if (!validated.ok) return validated.response;

  const registerResult = await callApi('/auth/register', { method: 'POST', body: validated.data });
  if (registerResult.kind !== 'ok') return relayFailure(registerResult);

  const user = parseEnvelopeData(registerResult.json, UserSchema);
  if (user === null) return jsonResponse(502, { message: HOUSE_SERVER_MESSAGE });

  const loginResult = await callApi('/auth/login', {
    method: 'POST',
    body: { email: validated.data.email, password: validated.data.password, platform: 'web' },
  });

  const session =
    loginResult.kind === 'ok' ? parseEnvelopeData(loginResult.json, SessionSchema) : null;
  if (session === null) {
    return jsonResponse(201, { user, signedIn: false, email: validated.data.email });
  }

  return withSetCookies(jsonResponse(201, { user: session.user }), session, Date.now());
}

/**
 * The only handler that reads the refresh cookie, and the only one the browser's single-flight
 * caller (`refreshSession`) ever calls. Short-circuits to 401 + cleared cookies when the
 * cookie is absent or cannot possibly be a real refresh token (validated through
 * `RefreshSchema` itself rather than a hand-rolled length check, so the "exactly 64
 * characters" rule lives in exactly one place) - a pointless round trip for a value that can
 * never succeed upstream.
 *
 * On 200 it re-sets BOTH cookies, because every rotation issues a FRESH 30-day refresh token
 * (§9.1.1 - a sliding window, no absolute cap in Cycle 1); re-setting only the access cookie
 * would silently cap the session at the first refresh token's 30 days.
 *
 * A 401 - and ONLY a 401, the API's one identical answer for unknown, expired, revoked AND
 * replayed - is terminal: both credentials are discarded and the body is `{signedIn: false}`
 * with nothing that could read as an accusation.
 *
 * FIX ROUND 1, IMPORTANT 3: every OTHER non-ok outcome (a non-401 error status such as a 500,
 * 503 or 429, or a `transportFailure`) is treated identically to each other and NOT as
 * terminal - no cookie write at all. The original code cleared both cookies on ANY non-ok
 * result except `transportFailure`, which contradicted this very docblock's own reasoning
 * ("a transportFailure is never evidence the credential is bad") for every status code that
 * is not 401 but also is not a network failure: a 503 is the identical "the API didn't
 * actually evaluate the token" situation and was getting the opposite treatment. Left
 * untouched, cookies let the browser's caller retry once the outage clears, rather than
 * discarding a refresh token that was never examined and forcing a password re-entry.
 */
export async function refreshHandler(request: Request): Promise<Response> {
  const csrfFailure = csrfGuard(request);
  if (csrfFailure !== null) return csrfFailure;

  const { refresh } = readSessionCookies(request.headers.get('cookie'));
  const candidate = RefreshSchema.safeParse({ refresh_token: refresh ?? '' });
  if (!candidate.success) {
    return withClearedCookies(jsonResponse(401, { signedIn: false }));
  }

  const result = await callApi('/auth/refresh', { method: 'POST', body: candidate.data });

  if (result.kind === 'ok') {
    const session = parseEnvelopeData(result.json, SessionSchema);
    if (session === null) return jsonResponse(502, { message: HOUSE_SERVER_MESSAGE });
    return withSetCookies(jsonResponse(200, { signedIn: true }), session, Date.now());
  }

  if (result.kind === 'error' && result.status === 401) {
    return withClearedCookies(jsonResponse(401, { signedIn: false }));
  }

  const status = result.kind === 'transportFailure' ? 502 : result.status;
  return jsonResponse(status, { signedIn: false });
}

/**
 * Posts both the bearer and `{refresh_token}` (`LogoutSchema`) only when BOTH cookies are
 * present and the refresh cookie can possibly be real - otherwise there is nothing valid to
 * revoke and the round trip is skipped. Either way this handler ALWAYS returns 204 with both
 * cookies cleared, whatever the API said: the access token may have expired while the tab sat
 * open, and leaving cookies behind after a "failed" logout is how a user stays looking signed
 * in. Never refreshes to obtain a fresh bearer first - that would mint one token purely to
 * revoke another.
 */
export async function logoutHandler(request: Request): Promise<Response> {
  const csrfFailure = csrfGuard(request);
  if (csrfFailure !== null) return csrfFailure;

  const { access, refresh } = readSessionCookies(request.headers.get('cookie'));
  const candidate = refresh === null ? null : LogoutSchema.safeParse({ refresh_token: refresh });

  if (access !== null && candidate !== null && candidate.success) {
    await callApi('/auth/logout', { method: 'POST', bearer: access, body: candidate.data });
  }

  return withClearedCookies(noBodyResponse(204));
}

/**
 * Validates with `ForgotPasswordSchema`, forwards, and answers 202 with an EMPTY body on
 * success - never parsing a response body here and never implying whether an account exists
 * is the whole point of this endpoint's design. A 429's `Retry-After` is forwarded by
 * `relayFailure`. No cookie writes.
 */
export async function forgotPasswordHandler(request: Request): Promise<Response> {
  const csrfFailure = csrfGuard(request);
  if (csrfFailure !== null) return csrfFailure;

  const validated = await validateBody(request, ForgotPasswordSchema);
  if (!validated.ok) return validated.response;

  const result = await callApi('/auth/forgot-password', { method: 'POST', body: validated.data });
  if (result.kind === 'ok') return noBodyResponse(202);
  return relayFailure(result);
}

/**
 * Validates with `ResetPasswordSchema`, forwards; on 204 it clears BOTH cookies (the reset
 * revoked every token the user held server-side, so the BFF's own idea of the session must
 * agree) and returns 204.
 *
 * A 422 - and ONLY a 422 - is forwarded with its envelope INTACT, deliberately NOT run
 * through `userMessageFor`/`fieldErrorsFor` the way every other failure here is. An invalid
 * reset token's `errors.token` carries the same sentence as `message`, and the page renders
 * it as a form-level message with a "Request a new link" action, never against the hidden
 * token input; reshaping the envelope here would be one more place that sentence has to stay
 * byte-for-byte in sync with the one the API actually sent.
 *
 * FIX ROUND 1, IMPORTANT 1: every other status (500, 503, …) goes through `relayFailure`
 * instead, exactly like every other handler. The original code forwarded `result.envelope`
 * intact at EVERY error status, which is a raw-backend-text leak (blueprint §135) for anything
 * that is not a 422 - `ErrorEnvelopeSchema` is a non-strict `z.object`, so a Laravel
 * debug-mode 500 carrying `{message: 'SQLSTATE[23000]: …'}` parsed as a valid envelope and
 * relayed verbatim. 422 is safe to forward intact only because it is already on
 * `userMessageFor`'s authored-status allowlist, so the two paths agree for every value the
 * API actually sends at that one status - that agreement does not extend to any other status.
 */
export async function resetPasswordHandler(request: Request): Promise<Response> {
  const csrfFailure = csrfGuard(request);
  if (csrfFailure !== null) return csrfFailure;

  const validated = await validateBody(request, ResetPasswordSchema);
  if (!validated.ok) return validated.response;

  const result = await callApi('/auth/reset-password', { method: 'POST', body: validated.data });

  if (result.kind === 'ok') {
    return withClearedCookies(noBodyResponse(204));
  }

  if (result.kind === 'error' && result.status === 422) {
    return jsonResponse(422, result.envelope ?? { message: HOUSE_SERVER_MESSAGE });
  }

  return relayFailure(result);
}

/**
 * Takes the RAW query substring from `request.url` by `indexOf('?')` and appends it to
 * `/auth/verify-email` with NO body. `UrlGenerator::hasCorrectSignature` rebuilds the signed
 * original from the raw query string in arrival order; rebuilding it with `URLSearchParams`
 * changes bytes (measured: `%20` becomes `+`, `~` becomes `%7E`, `%2A` becomes `*`), and any
 * such change is a permanent 403. Taking the substring from `request.url` directly - never
 * through `new URL(...).search` or `.searchParams` - removes even the possibility of a
 * normalisation step. There is deliberately no local schema validation of the four query
 * parameters: the client cannot usefully recompute `signature`, so a local check would only
 * reject a malformed link the server was going to reject anyway, for no benefit.
 */
export async function verifyEmailHandler(request: Request): Promise<Response> {
  const csrfFailure = csrfGuard(request);
  if (csrfFailure !== null) return csrfFailure;

  const questionIndex = request.url.indexOf('?');
  const rawQuery = questionIndex === -1 ? '' : request.url.slice(questionIndex);

  const result = await callApi(`/auth/verify-email${rawQuery}`, { method: 'POST' });

  if (result.kind !== 'ok') return relayFailure(result);

  const user = parseEnvelopeData(result.json, UserSchema);
  if (user === null) return jsonResponse(502, { message: HOUSE_SERVER_MESSAGE });

  return jsonResponse(200, { user });
}

/**
 * The only GET, and therefore the only handler that skips `assertSameOrigin` - it reads
 * nothing cross-origin tooling can forge into a mutation. `signedOut` with no API call when
 * there is no access cookie at all; otherwise `GET /me` with the bearer, mapping 200 to
 * `signedIn` and a 401 - and ONLY a 401 - to `expired`. `expired` is NOT `signedOut` - that
 * distinction is what lets the browser's caller attempt one refresh instead of showing a
 * sign-in page to someone who, as far as they know, is still signed in.
 *
 * FIX ROUND 1, IMPORTANT 3: a non-401 error status or a `transportFailure` is NEITHER
 * `signedIn` NOR `expired` NOR `signedOut` - there is no fourth state in this handler's
 * contract (`useSession.ts`'s `SessionResultSchema` is a strict three-member discriminated
 * union), so these now fall through to `relayFailure`, which answers with a non-2xx status
 * and a body that has no `status` field at all. `useSession.ts`'s `fetchSession` throws on a
 * non-2xx before it ever reaches `SessionResultSchema.parse`, so this surfaces as a THROWN
 * query error that react-query retries a bounded number of times - it is never read as
 * `expired` and never triggers `refreshSession()`. Before this fix, EVERY non-ok result
 * (including a 500 that never looked at the access token, and a transport failure that never
 * reached the API at all) mapped to `expired`, so a brief API outage walked every open web
 * session through expired -> refresh -> (the API still down) -> refreshHandler's own
 * over-eager clearing -> both credentials discarded -> signed out, forcing a password
 * re-entry for a refresh token that was never invalid.
 */
export async function sessionHandler(request: Request): Promise<Response> {
  const { access } = readSessionCookies(request.headers.get('cookie'));
  if (access === null) return jsonResponse(200, { status: 'signedOut' });

  const result = await callApi('/me', { method: 'GET', bearer: access });

  if (result.kind === 'ok') {
    const user = parseEnvelopeData(result.json, UserSchema);
    if (user === null) return jsonResponse(502, { message: HOUSE_SERVER_MESSAGE });
    return jsonResponse(200, { status: 'signedIn', user });
  }

  if (result.kind === 'error' && result.status === 401) {
    return jsonResponse(200, { status: 'expired' });
  }

  return relayFailure(result);
}
