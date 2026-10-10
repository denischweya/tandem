import { describe, expect, it } from 'vitest';

import { assertSameOrigin, CsrfError } from './csrf';

function post(headers: Record<string, string>): Request {
  return new Request('https://tandem.test/api/auth/login', { method: 'POST', headers });
}

describe('assertSameOrigin', () => {
  it('accepts a same-origin POST', () => {
    expect(() =>
      assertSameOrigin(post({ origin: 'https://tandem.test', host: 'tandem.test' })),
    ).not.toThrow();
  });

  it('rejects a cross-origin POST', () => {
    // The gap SameSite=Lax leaves: a top-level POST navigation from another site.
    expect(() =>
      assertSameOrigin(post({ origin: 'https://evil.test', host: 'tandem.test' })),
    ).toThrow(CsrfError);
  });

  it('rejects a POST with no Origin at all', () => {
    // Browsers always send Origin on a POST, including a cross-site form post. A request
    // without one is not a browser, and this is a browser-only BFF.
    expect(() => assertSameOrigin(post({ host: 'tandem.test' }))).toThrow(CsrfError);
  });

  it('rejects Sec-Fetch-Site: cross-site even when Origin matches', () => {
    expect(() =>
      assertSameOrigin(
        post({
          origin: 'https://tandem.test',
          host: 'tandem.test',
          'sec-fetch-site': 'cross-site',
        }),
      ),
    ).toThrow(CsrfError);
  });

  it('accepts Sec-Fetch-Site: same-origin', () => {
    expect(() =>
      assertSameOrigin(
        post({
          origin: 'https://tandem.test',
          host: 'tandem.test',
          'sec-fetch-site': 'same-origin',
        }),
      ),
    ).not.toThrow();
  });

  it('honours X-Forwarded-Host, so a proxy does not break every mutation', () => {
    expect(() =>
      assertSameOrigin(
        post({
          origin: 'https://tandem.test',
          host: 'internal:3000',
          'x-forwarded-host': 'tandem.test',
        }),
      ),
    ).not.toThrow();
  });

  it('takes the FIRST X-Forwarded-Host when a proxy appends rather than replaces it (fix round 1, Minor)', () => {
    // Not browser-reachable - a cross-origin fetch adding this header needs a preflight this
    // endpoint never answers, and a form post cannot set arbitrary headers at all - but a
    // proxy that appends a second X-Forwarded-Host instead of replacing the first turns
    // EVERY legitimate mutation into a 403: Headers.get() joins same-named headers with
    // ', ', so the unhardened version compared originHost against the whole
    // "tandem.test, internal-hop.test" string and never matched. The convention this
    // follows (the first hop's value is the original client-facing host; later values are
    // appended by each additional proxy) is the same one X-Forwarded-For uses.
    const headers = new Headers({ origin: 'https://tandem.test', host: 'internal:3000' });
    headers.append('x-forwarded-host', 'tandem.test');
    headers.append('x-forwarded-host', 'internal-hop.test');

    expect(() =>
      assertSameOrigin(
        new Request('https://tandem.test/api/auth/login', { method: 'POST', headers }),
      ),
    ).not.toThrow();
  });
});
