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
  // cookies are accepted there; e2e/live/auth.spec.ts asserts the cookie is actually SET
  // rather than assuming that exemption holds.
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

export function readSessionCookies(cookieHeader: string | null): {
  access: string | null;
  refresh: string | null;
} {
  const jar = new Map<string, string>();
  for (const part of (cookieHeader ?? '').split(';')) {
    const index = part.indexOf('=');
    if (index === -1) continue;
    jar.set(part.slice(0, index).trim(), decodeURIComponent(part.slice(index + 1).trim()));
  }
  return { access: jar.get(ACCESS_COOKIE) ?? null, refresh: jar.get(REFRESH_COOKIE) ?? null };
}
