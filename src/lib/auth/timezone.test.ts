import { afterEach, describe, expect, it, vi } from 'vitest';

import { resolveTimezone } from './timezone';

describe('resolveTimezone', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns whatever Intl resolves on a normal ICU build', () => {
    expect(resolveTimezone()).toBe(Intl.DateTimeFormat().resolvedOptions().timeZone);
  });

  it('falls back to UTC rather than sending the empty string on a minimal ICU build', () => {
    // MEASURED reason this matters: ConvertEmptyStringsToNull turns an empty `timezone` into
    // `null` server-side, which fails RegisterSchema's required `string` - so an empty zone
    // would 422 a registration for a reason the visitor cannot see or fix. 'UTC' is itself a
    // valid IANA zone.
    vi.spyOn(Intl, 'DateTimeFormat').mockReturnValue({
      resolvedOptions: () => ({ timeZone: '' }),
    } as unknown as Intl.DateTimeFormat);

    expect(resolveTimezone()).toBe('UTC');
  });

  it('also falls back to UTC when resolvedOptions omits timeZone entirely', () => {
    vi.spyOn(Intl, 'DateTimeFormat').mockReturnValue({
      resolvedOptions: () => ({}),
    } as unknown as Intl.DateTimeFormat);

    expect(resolveTimezone()).toBe('UTC');
  });
});
