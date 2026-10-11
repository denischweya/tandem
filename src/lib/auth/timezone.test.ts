import { afterEach, describe, expect, it, vi } from 'vitest';

import { resolveTimezone } from './timezone';

/**
 * A REAL `Intl.DateTimeFormat` instance with its own `resolvedOptions` shadowed by an own
 * property reporting `timeZone`. Deliberately not a plain object literal cast through
 * `unknown` - the global constraint against `as unknown as` applies here too, and a real
 * instance with one method overridden needs no cast at all: every other property/method on it
 * is the genuine implementation, so it satisfies `Intl.DateTimeFormat` structurally rather
 * than by assertion.
 */
// Captured before any `vi.spyOn(Intl, 'DateTimeFormat')` runs in any test in this file - the
// NATIVE constructor, not whatever the global reference currently points to. MEASURED: once
// `Intl.DateTimeFormat` IS spied, `new Intl.DateTimeFormat()` inside this helper stops
// returning an object with a working `resolvedOptions` at all (a vi.fn()-based spy wrapper
// does not replicate a native class's internal slots), which is a second, independent reason
// this repo's `apiEmail`-style "never go through a value that might already be mocked" habit
// matters, not just a cast-avoidance trick.
const NativeDateTimeFormat = Intl.DateTimeFormat;

function fakeDateTimeFormat(timeZone: string): Intl.DateTimeFormat {
  const real = new NativeDateTimeFormat();
  const options = real.resolvedOptions();
  real.resolvedOptions = () => ({ ...options, timeZone });
  return real;
}

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
    vi.spyOn(Intl, 'DateTimeFormat').mockReturnValue(fakeDateTimeFormat(''));

    expect(resolveTimezone()).toBe('UTC');
  });
});
