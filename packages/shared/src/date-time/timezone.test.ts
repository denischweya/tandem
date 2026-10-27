import { describe, expect, it } from 'vitest';
import { instantFromZonedParts, toZonedParts } from './timezone';

describe('toZonedParts', () => {
  it('renders an instant in a named zone', () => {
    const parts = toZonedParts(new Date('2026-10-10T16:30:00Z'), 'Africa/Nairobi');
    expect(parts).toEqual({ year: 2026, month: 10, day: 10, hour: 19, minute: 30 });
  });

  it('does not change the underlying instant when the display zone changes', () => {
    const instant = new Date('2026-10-10T16:30:00Z');
    const nairobi = instantFromZonedParts(
      toZonedParts(instant, 'Africa/Nairobi'),
      'Africa/Nairobi',
    );
    const newYork = instantFromZonedParts(
      toZonedParts(instant, 'America/New_York'),
      'America/New_York',
    );
    expect(nairobi.getTime()).toBe(instant.getTime());
    expect(newYork.getTime()).toBe(instant.getTime());
  });
});

describe('instantFromZonedParts', () => {
  it('resolves a local time across a spring-forward transition', () => {
    // London moves 01:00 -> 02:00 on 2027-03-28. 01:30 does not exist.
    const instant = instantFromZonedParts(
      { year: 2027, month: 3, day: 28, hour: 1, minute: 30 },
      'Europe/London',
    );
    expect(instant.toISOString()).toBe('2027-03-28T01:30:00.000Z');
  });

  it('resolves a local time across an autumn-back transition deterministically', () => {
    // London moves 02:00 -> 01:00 on 2027-10-31. 01:30 happens twice; we take the first.
    const instant = instantFromZonedParts(
      { year: 2027, month: 10, day: 31, hour: 1, minute: 30 },
      'Europe/London',
    );
    expect(instant.toISOString()).toBe('2027-10-31T00:30:00.000Z');
  });
});
