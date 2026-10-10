import { toZonedParts } from '@tandem/shared';
import { apiBaseUrl } from '@/server/env';

export default function Home() {
  const parts = toZonedParts(new Date(), 'Africa/Nairobi');
  const hhmm = `${String(parts.hour).padStart(2, '0')}:${String(parts.minute).padStart(2, '0')}`;

  // m4: the pre-existing nairobi-time assertion is /^\d{2}:\d{2}$/, which passes if
  // toZonedParts returns UTC, the wrong day, or "00:00" - it does not test the time zone,
  // which is the helper's entire purpose. This fixes an instant to a known UTC moment and
  // asserts the Africa/Nairobi-local hour/minute it must produce (16:30 UTC -> 19:30
  // Nairobi, UTC+3, no DST), so a regression to UTC (16:30) or to a wrong offset is caught.
  const fixed = toZonedParts(new Date('2026-10-10T16:30:00.000Z'), 'Africa/Nairobi');
  const fixedHhmm = `${String(fixed.hour).padStart(2, '0')}:${String(fixed.minute).padStart(2, '0')}`;

  return (
    <main style={{ padding: '3rem', fontFamily: 'system-ui, sans-serif' }}>
      <h1>Tandem</h1>
      <p data-testid="api-base-url">API: {apiBaseUrl()}</p>
      <p data-testid="nairobi-time">{hhmm}</p>
      <p data-testid="nairobi-fixed">{fixedHhmm}</p>
    </main>
  );
}
