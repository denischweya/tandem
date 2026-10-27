import { toZonedParts } from '@tandem/shared';

const MONTH_NAMES = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
] as const;

const WEEKDAY_NAMES = [
  'Sunday',
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
] as const;

/**
 * Day of week for a calendar date, via Sakamoto's algorithm (proleptic
 * Gregorian calendar). Operates purely on the {year, month, day} shape that
 * `toZonedParts` already produced for the target zone, so the weekday is
 * derived from that zone-correct wall-clock date rather than by constructing
 * a second `Date` and reading it back in some other (ambient/local) zone.
 */
function weekdayIndex(year: number, month: number, day: number): number {
  const t = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4];
  // `month` is a 1-12 wall-clock month from `toZonedParts`, so `month - 1` is always a valid
  // index into `t` — but `noUncheckedIndexedAccess` cannot see that invariant through a
  // computed index, even on a 12-element tuple (finding I6, spec §14.1). Assert it explicitly
  // rather than silently falling back to a wrong weekday.
  const tValue = t[month - 1];
  if (tValue === undefined) {
    throw new RangeError(`weekdayIndex: month out of range: ${month}`);
  }
  const y = month < 3 ? year - 1 : year;
  return (y + Math.floor(y / 4) - Math.floor(y / 100) + Math.floor(y / 400) + tValue + day) % 7;
}

/** Same out-of-range guard as `weekdayIndex`, for the two name lookups below. */
function nameAt(names: readonly string[], index: number, label: string): string {
  const name = names[index];
  if (name === undefined) {
    throw new RangeError(`formatInviteWhen: ${label} index out of range: ${index}`);
  }
  return name;
}

export interface InviteWhen {
  /** "Saturday" */
  weekday: string;
  /** "10 October" */
  monthDay: string;
  /** "7 PM" or "7:30 PM" */
  time: string;
}

/**
 * Formats a plan's start instant for display in the plan's own time zone.
 *
 * Deliberately does not hand-format a `Date` with `getDay`/`getMonth`/etc:
 * those read back in whatever zone the JS runtime happens to be in, which is
 * exactly the bug `toZonedParts` exists to avoid (see `@tandem/shared`). The
 * zoned parts are the single source of truth here — everything below is
 * plain arithmetic/lookup over those numbers, not a second zoned read.
 */
export function formatInviteWhen(startInstant: string, timeZone: string): InviteWhen {
  const parts = toZonedParts(new Date(startInstant), timeZone);

  const weekday = nameAt(
    WEEKDAY_NAMES,
    weekdayIndex(parts.year, parts.month, parts.day),
    'weekday',
  );
  const monthDay = `${parts.day} ${nameAt(MONTH_NAMES, parts.month - 1, 'month')}`;

  const hour12 = parts.hour % 12 === 0 ? 12 : parts.hour % 12;
  const meridiem = parts.hour < 12 ? 'AM' : 'PM';
  const time =
    parts.minute === 0
      ? `${hour12} ${meridiem}`
      : `${hour12}:${String(parts.minute).padStart(2, '0')} ${meridiem}`;

  return { weekday, monthDay, time };
}
