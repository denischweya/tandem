import type { z } from 'zod';
import type { SessionSchema } from '@tandem/shared';

export const ACCESS_COOKIE = 'tandem_at';
export const REFRESH_COOKIE = 'tandem_rt';

/** Path=/ so a Server Component render can attach a bearer - a render receives exactly what
 *  the browser sent, and a path-scoped access cookie would make every such render look
 *  signed out. */
const ACCESS_PATH = '/';
/** Path=/api/auth so the 30-day credential is sent to the four handlers that need it and to
 *  nothing else. A Path=/ refresh cookie would travel with every navigation and asset
 *  request. */
const REFRESH_PATH = '/api/auth';

/**
 * Seconds until the given expiry, floored at 0.
 *
 * Derived from the RESPONSE and not from TokenIssuer::TTL_MINUTES (15) and
 * RefreshTokenIssuer::TTL_DAYS (30). SessionResource's docblock gives the reason both expiries
 * are on the wire at all: "the web BFF sets each cookie's Max-Age from them." Hardcoding 900
 * and 2592000 reintroduces clock skew between the Next server and the API, and a cookie that
 * outlives its token produces 401s the BFF reads as a dead session.
 */
export function maxAgeFromExpiry(expiresAtIso: string, nowMs: number): number {
  const expiresAtMs = Date.parse(expiresAtIso);
  if (Number.isNaN(expiresAtMs)) return 0;
  return Math.max(0, Math.floor((expiresAtMs - nowMs) / 1000));
}

function serialize(name: string, value: string, path: string, maxAge: number): string {
  // `Secure` with no environment carve-out, as ADR 0004 requires. Playwright runs against
  // http://localhost:3100 and Chromium treats localhost as a secure context, so Secure
  // cookies are accepted there.
  //
  // FIX ROUND 1, MINOR (seventh brief defect): this comment named `e2e/live/auth.spec.ts`
  // as asserting the cookie is actually set - that file does not exist. `e2e/` holds only
  // `smoke.spec.ts` and `invite.spec.ts`, and no e2e test in this repo touches any BFF
  // route; `sessionSetCookies`'s own unit tests (cookies.test.ts) are what currently prove
  // `Secure` is present. The brief supplied the line this replaced verbatim; it cited a test
  // that was never written rather than the one that exists. Writing the cited test itself is
  // out of scope here - it needs either a live API or a mocked one behind Playwright's
  // webServer, neither of which this task's file list includes - so the citation is
  // corrected rather than the test manufactured.
  return [
    `${name}=${encodeURIComponent(value)}`,
    `Path=${path}`,
    `Max-Age=${maxAge}`,
    'HttpOnly',
    'Secure',
    'SameSite=Lax',
  ].join('; ');
}

export function sessionSetCookies(
  payload: z.infer<typeof SessionSchema>,
  nowMs: number,
): [string, string] {
  return [
    serialize(
      ACCESS_COOKIE,
      payload.access_token,
      ACCESS_PATH,
      maxAgeFromExpiry(payload.access_token_expires_at, nowMs),
    ),
    serialize(
      REFRESH_COOKIE,
      payload.refresh_token,
      REFRESH_PATH,
      maxAgeFromExpiry(payload.refresh_token_expires_at, nowMs),
    ),
  ];
}

export function clearedSetCookies(): [string, string] {
  return [
    serialize(ACCESS_COOKIE, '', ACCESS_PATH, 0),
    serialize(REFRESH_COOKIE, '', REFRESH_PATH, 0),
  ];
}

/**
 * FIX ROUND 1, IMPORTANT 6: `decodeURIComponent` throws a raw `URIError` on a malformed
 * percent-encoding (`decodeURIComponent('%')`, measured). Unguarded, that turned a single bad
 * cookie into a Next 500 across the ENTIRE auth surface - `sessionHandler`, `logoutHandler`
 * and `refreshHandler` all call `readSessionCookies` with nothing to catch it, and
 * `src/server/session.ts`'s `getSession()` does too. Because `logoutHandler` also threw, the
 * app had no way to clear the bad cookie itself - recovery needed the browser's own cookie
 * settings. A cookie value is attacker-influenceable in several ways that do not require
 * `HttpOnly` to be absent (a sibling subdomain setting a `Domain` cookie of the same name, or
 * corruption in transit), so this is treated as untrusted input, not a can't-happen value.
 * One malformed value is swallowed to "absent" rather than failing the whole header; it does
 * not poison a well-formed cookie sitting next to it in the same header.
 */
function decodeCookieValue(raw: string): string | null {
  try {
    return decodeURIComponent(raw);
  } catch {
    return null;
  }
}

export function readSessionCookies(cookieHeader: string | null): {
  access: string | null;
  refresh: string | null;
} {
  const jar = new Map<string, string>();
  for (const part of (cookieHeader ?? '').split(';')) {
    const index = part.indexOf('=');
    if (index === -1) continue;
    const decoded = decodeCookieValue(part.slice(index + 1).trim());
    if (decoded === null) continue;
    jar.set(part.slice(0, index).trim(), decoded);
  }
  return { access: jar.get(ACCESS_COOKIE) ?? null, refresh: jar.get(REFRESH_COOKIE) ?? null };
}
