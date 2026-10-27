export class CsrfError extends Error {
  constructor() {
    super('Cross-origin request refused.');
    this.name = 'CsrfError';
  }
}

/**
 * CSRF protection for every mutating BFF route. ADR 0004: "CSRF protection on the BFF's own
 * mutating routes is still required and is not optional" - and a grep for `csrf` across all of
 * tandem-docs returns exactly that one sentence, so the mechanism is chosen here.
 *
 * `SameSite=Lax` does not cover a top-level POST navigation, which is the real gap. Browsers
 * always send `Origin` on a POST, including a cross-site form post, so comparing it against
 * the request's own host closes it; `Sec-Fetch-Site` is a second signal where the browser
 * sends one. Next provides no built-in CSRF for route handlers (Server Actions have their own
 * origin check; route handlers do not).
 *
 * Rejected alternative: a double-submit cookie token. It needs a non-HttpOnly cookie plus a
 * header that browser JS reads - more moving parts for the same guarantee, and nothing a
 * Playwright test can check as directly as "a POST from another origin is refused".
 *
 * Accepted limitation: a non-browser client that omits `Origin` is refused. This is a
 * browser-only BFF; a server-to-server caller has the API itself.
 *
 * Accepted limitation, recorded rather than closed (FIX ROUND 1, MINOR): only the HOST is
 * compared, not the scheme - an `http://tandem.test` origin would pass against a `host` of
 * `tandem.test`. Not closed here because `Secure` cookies are what actually matter and they
 * already refuse to leave the browser over plain HTTP, so an attacker who could present an
 * `http://tandem.test` Origin still presents with no cookie attached; adding a scheme check
 * would also need an operator-configured expected scheme (or a trusted `X-Forwarded-Proto`)
 * to stay correct behind TLS-terminating proxies and in local dev over HTTP, which is a
 * deployment assumption worth a deliberate decision rather than a reflexive addition here.
 */
export function assertSameOrigin(request: Request): void {
  const fetchSite = request.headers.get('sec-fetch-site');
  if (fetchSite !== null && fetchSite !== 'same-origin' && fetchSite !== 'none')
    throw new CsrfError();

  const origin = request.headers.get('origin');
  if (origin === null) throw new CsrfError();

  const host = lastForwardedHost(request) ?? request.headers.get('host');
  if (host === null) throw new CsrfError();

  let originHost: string;
  try {
    originHost = new URL(origin).host;
  } catch {
    throw new CsrfError();
  }
  if (originHost !== host) throw new CsrfError();
}

/**
 * `Headers.get` joins repeated headers of the same name with `", "` (Fetch spec). A single
 * trusted proxy hop sets `X-Forwarded-Host` once, and `.get()` returns that one value
 * untouched - the comma-split below is a no-op for that ordinary case. It only matters for a
 * misconfigured proxy that APPENDS a second hop's value instead of replacing the first: with
 * no split, the whole `"tandem.test, internal-hop.test"` string never equals a bare hostname
 * and every mutation 403s.
 *
 * FIX ROUND 2, M2: round 1 took the FIRST value here, reasoning (wrongly) by analogy with
 * `X-Forwarded-For`. MEASURED: the first value is the CLIENT-INJECTABLE end, not the
 * trustworthy one - a request (hypothetically, since none of this is browser-reachable
 * today, as this file's own docblock says) carrying `X-Forwarded-Host: evil.test` would have
 * a legitimate proxy hop APPEND the real host after it (`"evil.test, tandem.test"`), and
 * taking the first value matched the attacker's own forged entry rather than the proxy's.
 * The LAST value is the one closest to this server - the nearest hop's own appended entry -
 * which is what is actually trustworthy in a conventional append-per-hop chain.
 */
function lastForwardedHost(request: Request): string | null {
  const raw = request.headers.get('x-forwarded-host');
  if (raw === null) return null;
  const parts = raw.split(',');
  const last = parts[parts.length - 1]?.trim();
  return last !== undefined && last.length > 0 ? last : null;
}
