export interface ZonedParts {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
}

const FORMATTERS = new Map<string, Intl.DateTimeFormat>();

function formatterFor(timeZone: string): Intl.DateTimeFormat {
  const cached = FORMATTERS.get(timeZone);
  if (cached) return cached;

  const formatter = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hour12: false,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  });
  FORMATTERS.set(timeZone, formatter);
  return formatter;
}

/** Render an instant as wall-clock parts in a named IANA zone. */
export function toZonedParts(instant: Date, timeZone: string): ZonedParts {
  const parts = formatterFor(timeZone).formatToParts(instant);
  const read = (type: Intl.DateTimeFormatPartTypes): number => {
    const found = parts.find((p) => p.type === type);
    if (!found) throw new Error(`Missing ${type} for zone ${timeZone}`);
    return Number.parseInt(found.value, 10);
  };

  // Intl renders midnight as hour 24 in some locales; normalise it.
  const hour = read('hour');

  return {
    year: read('year'),
    month: read('month'),
    day: read('day'),
    hour: hour === 24 ? 0 : hour,
    minute: read('minute'),
  };
}

function samePartsAs(a: ZonedParts, b: ZonedParts): boolean {
  return (
    a.year === b.year &&
    a.month === b.month &&
    a.day === b.day &&
    a.hour === b.hour &&
    a.minute === b.minute
  );
}

/** Offset in ms between a zone's wall clock and UTC at a given instant. */
function offsetAt(instant: Date, timeZone: string): number {
  const p = toZonedParts(instant, timeZone);

  return Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute) - instant.getTime();
}

/**
 * Resolve wall-clock parts in a named zone to an instant.
 *
 * A naive correction loop is not enough. An ambiguous local time - the hour that
 * repeats when a zone falls back - has TWO valid instants, and a loop that stops at
 * the first zero-drift candidate returns whichever it happens to reach first, which
 * is the later one. The tests require the earlier, so the candidates are enumerated
 * and the minimum is taken.
 *
 * Candidates come from probing the zone's offset at asUtc +/- 14 hours and at asUtc
 * itself: 14, not 12, because real offsets run from -12:00 to +14:00 and a 12-hour
 * bracket misses Pacific/Kiritimati and its neighbours entirely.
 *
 * A nonexistent local time - the hour skipped when a zone springs forward - has NO
 * valid instant, so `valid` is empty and the drift loop below handles it. Guarding
 * on `valid.length > 0` is not optional: `Math.min(...[])` is `Infinity`, and
 * `new Date(Infinity).toISOString()` throws `RangeError: Invalid time value`.
 */
export function instantFromZonedParts(parts: ZonedParts, timeZone: string): Date {
  const asUtc = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute);
  const fourteenHours = 14 * 60 * 60 * 1000;

  const offsets = new Set<number>();
  for (const probe of [asUtc - fourteenHours, asUtc, asUtc + fourteenHours]) {
    offsets.add(offsetAt(new Date(probe), timeZone));
  }

  const valid: number[] = [];
  for (const offset of offsets) {
    const candidate = new Date(asUtc - offset);
    if (samePartsAs(toZonedParts(candidate, timeZone), parts)) {
      valid.push(candidate.getTime());
    }
  }

  if (valid.length > 0) {
    return new Date(Math.min(...valid));
  }

  // Nonexistent local time: no candidate renders back to the requested parts, so
  // settle just past the gap by correcting the drift.
  let instant = new Date(asUtc);
  for (let pass = 0; pass < 2; pass += 1) {
    const rendered = toZonedParts(instant, timeZone);
    const renderedUtc = Date.UTC(
      rendered.year,
      rendered.month - 1,
      rendered.day,
      rendered.hour,
      rendered.minute,
    );
    const drift = renderedUtc - asUtc;
    if (drift === 0) break;
    instant = new Date(instant.getTime() - drift);
  }

  return instant;
}
