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
 */
export function assertSameOrigin(request: Request): void {
  const fetchSite = request.headers.get('sec-fetch-site');
  if (fetchSite !== null && fetchSite !== 'same-origin' && fetchSite !== 'none')
    throw new CsrfError();

  const origin = request.headers.get('origin');
  if (origin === null) throw new CsrfError();

  const host = request.headers.get('x-forwarded-host') ?? request.headers.get('host');
  if (host === null) throw new CsrfError();

  let originHost: string;
  try {
    originHost = new URL(origin).host;
  } catch {
    throw new CsrfError();
  }
  if (originHost !== host) throw new CsrfError();
}
