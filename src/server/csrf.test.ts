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
});
