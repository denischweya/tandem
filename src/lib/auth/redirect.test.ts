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

  it('rejects undefined and the empty string', () => {
    expect(safeNextPath(undefined)).toBe('/plans');
    expect(safeNextPath('')).toBe('/plans');
  });

  it('honours a custom fallback', () => {
    expect(safeNextPath('https://evil.example', '/signin')).toBe('/signin');
  });
});
