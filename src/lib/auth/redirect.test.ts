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

  /**
   * Wave 1b final review, W1/2.2. All five shapes pass the leading-slash and leading-C0/
   * leading-`//`/`\` checks above untouched (one leading slash, second character `.`), but
   * the WHATWG URL Standard's own dot-segment removal collapses every one of them into a
   * pathname beginning `//` once actually resolved - measured same-origin
   * (`https://app.tandem.example//evil.example`), so the hazard is a hard
   * `history.replaceState` `SecurityError` crash, not a redirect to the attacker's origin -
   * but a crash on the first navigation after sign-in all the same. The class is five shapes,
   * not the one literal `/..//` a narrower fix might have targeted.
   */
  it('rejects every dot-segment shape that collapses to a `//`-prefixed pathname', () => {
    expect(safeNextPath('/..//evil.example')).toBe('/plans');
    expect(safeNextPath('/.//evil.example')).toBe('/plans');
    expect(safeNextPath('/../..//evil.example')).toBe('/plans');
    expect(safeNextPath('/a/..//evil.example')).toBe('/plans');
    expect(safeNextPath('/..//evil.example/x')).toBe('/plans');
  });

  it('rejects the %2F-encoded arrival of the dot-segment class the same way', () => {
    // Not a separate case to enumerate - `URLSearchParams` decodes `%2F` before this function
    // ever sees the candidate, so this arrives as the identical `/..//evil.example` string
    // the test above already covers. Asserted through the real decode anyway (the way
    // `redirect.test.ts`'s own %09 case already does), not hand-typed, per 4.16.
    const decoded = new URLSearchParams('next=%2F..%2F%2Fevil.example').get('next');
    expect(decoded).toBe('/..//evil.example');
    expect(safeNextPath(decoded ?? undefined)).toBe('/plans');
  });

  /**
   * Wave 1b final review, W2/2.3. The full U+0000..U+0020 sweep, not just the three
   * (tab/LF/CR) the character-class check above explicitly names. MEASURED: before this
   * sweep existed, 30 of these 33 were rejected only INCIDENTALLY (because the raw candidate
   * does not start with `/` when a control character is its first byte) rather than
   * deliberately - an untested incidental rejection for a hazard whose cost was already two
   * fix rounds and ~2,371 measured candidates. This sweep is pinned against the BACKSTOP
   * (`resolvesOffThisApp`), which is why it stays green even under the exact one-token
   * mutation named below - see this file's own break-and-restore proof in the fix report.
   */
  it('rejects every one of U+0000..U+0020 immediately before a scheme-relative target', () => {
    for (let codePoint = 0x0000; codePoint <= 0x0020; codePoint += 1) {
      const candidate = String.fromCodePoint(codePoint) + '//evil.example';
      expect(safeNextPath(candidate)).toBe('/plans');
    }
  });
});
