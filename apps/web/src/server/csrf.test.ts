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

  it('rejects a cross-origin claim injected via a client-sent X-Forwarded-Host (fix round 2, M2 - takes the LAST value, not leftmost)', () => {
    // Reviewer's measurement: leftmost took the CLIENT-INJECTABLE end. A same-origin fetch
    // cannot set this header cross-origin (no preflight answered here, and a form post
    // cannot set arbitrary headers at all - csrf.ts's own docblock says so), but IF a client
    // could send one, taking the leftmost value let `Origin: https://evil.test` plus a
    // client-sent `X-Forwarded-Host: evil.test` - with a legitimate proxy hop correctly
    // APPENDING the real host, `tandem.test`, after it - pass, because the leftmost
    // ("evil.test") matched the attacker's own forged Origin. The trustworthy value is the
    // LAST one (the hop closest to this server, i.e. the proxy's own), matching
    // X-Forwarded-For's convention of each additional hop APPENDING after the client's own
    // (spoofable) leading value.
    const headers = new Headers({ origin: 'https://evil.test', host: 'internal:3000' });
    headers.append('x-forwarded-host', 'evil.test'); // client-injected, untrustworthy
    headers.append('x-forwarded-host', 'tandem.test'); // the proxy's own, appended after it

    expect(() =>
      assertSameOrigin(
        new Request('https://tandem.test/api/auth/login', { method: 'POST', headers }),
      ),
    ).toThrow(CsrfError);
  });

  it('takes the LAST X-Forwarded-Host when a proxy appends rather than replaces it (fix round 1, Minor; corrected in round 2, M2)', () => {
    // Not browser-reachable - a cross-origin fetch adding this header needs a preflight this
    // endpoint never answers, and a form post cannot set arbitrary headers at all - but a
    // proxy that appends a second X-Forwarded-Host instead of replacing the first turns
    // EVERY legitimate mutation into a 403: Headers.get() joins same-named headers with
    // ', ', so the fully-unhardened version compared originHost against the whole
    // "internal-hop.test, tandem.test" string and never matched. The LAST value - the
    // nearest hop's own entry, appended after whatever came before it - is the trustworthy
    // one; see `lastForwardedHost`'s own docblock for why the FIRST value (round 1's choice)
    // is exactly backwards.
    const headers = new Headers({ origin: 'https://tandem.test', host: 'internal:3000' });
    headers.append('x-forwarded-host', 'internal-hop.test');
    headers.append('x-forwarded-host', 'tandem.test');

    expect(() =>
      assertSameOrigin(
        new Request('https://tandem.test/api/auth/login', { method: 'POST', headers }),
      ),
    ).not.toThrow();
  });
});
