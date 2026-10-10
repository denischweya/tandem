import { describe, expect, it } from 'vitest';

import {
  ACCESS_COOKIE,
  clearedSetCookies,
  maxAgeFromExpiry,
  readSessionCookies,
  REFRESH_COOKIE,
  sessionSetCookies,
} from './cookies';

const NOW = Date.parse('2026-10-10T12:00:00.000Z');

const payload = {
  access_token: 'uuid|abcdef',
  access_token_expires_at: '2026-10-10T12:15:00.000+00:00',
  refresh_token: 'f'.repeat(64),
  refresh_token_expires_at: '2026-11-09T12:00:00.000+00:00',
  user: {
    id: '0199e2a0-7b1c-7000-8000-000000000001',
    email: 'ada@example.test',
    email_verified: true,
    display_name: 'Ada Lovelace',
    timezone: 'Africa/Nairobi',
    locale: 'en',
  },
};

describe('maxAgeFromExpiry', () => {
  it('derives Max-Age from the response, not from a constant', () => {
    // SessionResource's docblock names this as the reason both expiries are on the wire at
    // all: "the web BFF sets each cookie's Max-Age from them." TokenIssuer::TTL_MINUTES = 15
    // and RefreshTokenIssuer::TTL_DAYS = 30 make 900 and 2592000 look obvious, and hardcoding
    // them reintroduces clock skew between the Next server and the API - a cookie that
    // outlives its token produces 401s the BFF reads as a dead session.
    expect(maxAgeFromExpiry(payload.access_token_expires_at, NOW)).toBe(900);
    expect(maxAgeFromExpiry(payload.refresh_token_expires_at, NOW)).toBe(30 * 24 * 60 * 60);
  });

  it('parses the +00:00 offset the API actually sends', () => {
    // DATE_RFC3339_EXTENDED renders UTC as +00:00, not Z.
    expect(maxAgeFromExpiry('2026-10-10T12:01:00.000+00:00', NOW)).toBe(60);
  });

  it('floors at zero rather than emitting a negative Max-Age', () => {
    expect(maxAgeFromExpiry('2026-10-10T11:00:00.000+00:00', NOW)).toBe(0);
  });

  it('floors at zero for an unparsable expiry', () => {
    expect(maxAgeFromExpiry('not-a-date', NOW)).toBe(0);
  });
});

describe('sessionSetCookies', () => {
  it('sets both cookies HttpOnly, Secure and SameSite=Lax', () => {
    const [access, refresh] = sessionSetCookies(payload, NOW);

    for (const cookie of [access, refresh]) {
      expect(cookie).toContain('HttpOnly');
      expect(cookie).toContain('Secure');
      expect(cookie).toContain('SameSite=Lax');
    }
  });

  it('scopes the access cookie to the whole site and the refresh cookie to /api/auth', () => {
    // Asymmetric on purpose. The access cookie must reach a Server Component render, which
    // receives exactly what the browser sent; the refresh cookie is a 30-day credential and
    // has no business travelling with every navigation and asset request.
    const [access, refresh] = sessionSetCookies(payload, NOW);
    expect(access).toContain(`${ACCESS_COOKIE}=uuid%7Cabcdef`);
    expect(access).toContain('Path=/;');
    expect(refresh).toContain(`${REFRESH_COOKIE}=${'f'.repeat(64)}`);
    expect(refresh).toContain('Path=/api/auth;');
  });

  it('derives each Max-Age from its own expiry', () => {
    const [access, refresh] = sessionSetCookies(payload, NOW);
    expect(access).toContain('Max-Age=900');
    // Every rotation issues a FRESH 30-day refresh token (§9.1.1 - sliding window, no
    // absolute cap in Cycle 1). A handler that re-sets only the access cookie silently caps
    // the session at the first refresh token's 30 days.
    expect(refresh).toContain(`Max-Age=${30 * 24 * 60 * 60}`);
  });

  it('recomputes against nowMs rather than a fixed constant for THIS fixture', () => {
    // The task's own break-and-restore step for this guard ("Hardcoded Max-Age") predicts
    // that replacing maxAgeFromExpiry(...) with the literals 900 and 2592000 inside this
    // function makes 'parses the +00:00 offset the API actually sends' fail. MEASURED: it
    // cannot - that test calls maxAgeFromExpiry directly and never calls sessionSetCookies,
    // so no edit inside this function can affect it, regardless of fixture values. The
    // suite's actual exposure is right here, and it WAS vacuous as given: with this file's
    // `payload` (access expiry exactly 900s after NOW, refresh exactly 30 days after NOW),
    // 'derives each Max-Age from its own expiry' above passes against a hardcoded 900 /
    // 2592000 just as readily as against the real computation - confirmed by actually
    // breaking it. A second nowMs that is NOT 900/2592000 seconds before either expiry is
    // the one assertion a constant cannot satisfy alongside the one above.
    const oneMinuteEarlier = NOW - 60_000;
    const [access, refresh] = sessionSetCookies(payload, oneMinuteEarlier);
    expect(access).toContain('Max-Age=960');
    expect(refresh).toContain(`Max-Age=${30 * 24 * 60 * 60 + 60}`);
  });

  it('percent-encodes the value, so a pipe in a Sanctum token cannot break the header', () => {
    expect(sessionSetCookies(payload, NOW)[0]).not.toContain('uuid|abcdef');
  });
});

describe('clearedSetCookies', () => {
  it('expires both cookies at their own paths', () => {
    const [access, refresh] = clearedSetCookies();
    expect(access).toContain('Max-Age=0');
    expect(access).toContain('Path=/;');
    expect(refresh).toContain('Max-Age=0');
    // A clear at the wrong Path leaves the cookie in place, which is how a user stays
    // "signed in" after a logout that reported success.
    expect(refresh).toContain('Path=/api/auth;');
  });
});

describe('readSessionCookies', () => {
  it('reads both from a cookie header', () => {
    expect(
      readSessionCookies(`${ACCESS_COOKIE}=uuid%7Cabcdef; ${REFRESH_COOKIE}=${'f'.repeat(64)}`),
    ).toEqual({ access: 'uuid|abcdef', refresh: 'f'.repeat(64) });
  });

  it('returns nulls for a missing header, so nothing downstream discriminates', () => {
    expect(readSessionCookies(null)).toEqual({ access: null, refresh: null });
    expect(readSessionCookies('')).toEqual({ access: null, refresh: null });
  });

  it('ignores an unrelated cookie', () => {
    expect(readSessionCookies('other=1')).toEqual({ access: null, refresh: null });
  });
});
