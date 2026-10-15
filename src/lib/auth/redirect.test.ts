import { describe, expect, it } from 'vitest';

import { safeNextPath } from './redirect';

/**
 * Fix round 1, Important 1 - the open-redirect guard. Every rejection case the finding named
 * as a minimum is asserted here, not just the accept case `SignInForm.test.tsx` already had.
 */
describe('safeNextPath', () => {
  it('accepts a valid same-document relative path', () => {
    expect(safeNextPath('/invite/abc123')).toBe('/invite/abc123');
    expect(safeNextPath('/plans')).toBe('/plans');
  });

  it('accepts a path carrying its own query string and fragment', () => {
    // Genuinely same-origin: `new URL()` resolves this against the current origin untouched.
    expect(safeNextPath('/invite/abc123?q=1#hash')).toBe('/invite/abc123?q=1#hash');
  });

  it('accepts a path whose slashes are themselves percent-encoded', () => {
    // `%2f` is a literal three-character path segment to the URL parser - it is never
    // decoded back into a real `/` before the origin is determined, so this is NOT the
    // scheme-relative hazard a real `//` prefix would be, and stays same-origin.
    expect(safeNextPath('/%2f%2fevil.example')).toBe('/%2f%2fevil.example');
  });

  it('rejects a scheme-relative URL (//evil.example) and falls back', () => {
    expect(safeNextPath('//evil.example')).toBe('/plans');
  });

  it('rejects an absolute https URL and falls back', () => {
    expect(safeNextPath('https://evil.example')).toBe('/plans');
  });

  it('rejects an absolute http URL and falls back', () => {
    expect(safeNextPath('http://evil.example')).toBe('/plans');
  });

  it('rejects a leading-backslash variant and falls back', () => {
    expect(safeNextPath('/\\evil.example')).toBe('/plans');
    expect(safeNextPath('/\\/evil.example')).toBe('/plans');
  });

  /**
   * Fix round 2, CRITICAL. The WHATWG URL parser strips every ASCII tab/LF/CR from the WHOLE
   * input before resolving it, so `/<TAB>/evil.example` becomes `//evil.example` -
   * scheme-relative - AFTER round 1's guard had already let it through (it starts with one
   * `/`, not literally `//` or `/\`). Each of these is the measured off-origin survivor from
   * the round-2 finding, reproduced directly at this function's boundary.
   */
  it('rejects a tab, LF or CR between the leading slash and a second slash', () => {
    expect(safeNextPath('/\t/evil.example')).toBe('/plans');
    expect(safeNextPath('/\n/evil.example')).toBe('/plans');
    expect(safeNextPath('/\r/evil.example')).toBe('/plans');
  });

  it('rejects a tab-then-backslash form the same way', () => {
    expect(safeNextPath('/\t\\evil.example')).toBe('/plans');
  });

  it('rejects the %09-encoded form as it actually arrives through URLSearchParams', () => {
    // Not a literal in this test file - this is exactly how the value reaches
    // `safeNextPath` in production: `next=/%09/evil.example` in the URL, decoded by
    // `URLSearchParams`/Next's `searchParams` before `SignInPage` ever passes it down.
    const decoded = new URLSearchParams('next=/%09/evil.example').get('next');
    expect(decoded).toBe('/\t/evil.example');
    expect(safeNextPath(decoded ?? undefined)).toBe('/plans');
  });

  it('rejects a tab/LF/CR anywhere in the candidate, not only right after the leading slash', () => {
    // The fix is a character-class test, unanchored - not limited to the four concrete
    // strings the finding listed. A stray control character deeper in the path is rejected
    // too, since this function refuses to validate a string the browser will mutate before
    // using it, regardless of where in the string that mutation would happen.
    expect(safeNextPath('/invite/abc\t123')).toBe('/plans');
    expect(safeNextPath('/invite/abc\n123')).toBe('/plans');
    expect(safeNextPath('/invite/abc\r123')).toBe('/plans');
  });

  it('rejects undefined and the empty string', () => {
    expect(safeNextPath(undefined)).toBe('/plans');
    expect(safeNextPath('')).toBe('/plans');
  });

  it('honours a custom fallback', () => {
    expect(safeNextPath('https://evil.example', '/signin')).toBe('/signin');
  });
});
