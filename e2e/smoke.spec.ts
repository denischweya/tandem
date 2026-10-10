import { expect, test } from '@playwright/test';

test('the home page renders and reports the API target', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Tandem' })).toBeVisible();
  await expect(page.getByTestId('api-base-url')).toContainText('/api/v1');
});

// "/" is statically prerendered (`next build` reports `○ (Static)`), so the Nairobi value
// is computed once at build time and frozen into the HTML, not recomputed per request. This
// test cannot claim per-request SSR — it proves the shared package is actually bundled and
// its code runs inside a Server Component during the build, not that it runs on every render.
test('the shared package is bundled and executed during the server render', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByTestId('nairobi-time')).toHaveText(/^\d{2}:\d{2}$/);
});

// Fix round 1, Important 5: review finding m4 was reported closed by rendering
// data-testid="nairobi-fixed", but nothing asserted it — rendering a value nothing checks
// closes nothing. /^\d{2}:\d{2}$/ above passes for UTC, the wrong day, or "00:00"; it does
// not test the time zone, which is toZonedParts's entire purpose. This fixes the exact
// instant page.tsx fixes (2026-10-10T16:30:00.000Z) and asserts the one Africa/Nairobi-local
// value it must produce (UTC+3, no DST): 19:30. A regression to UTC (16:30) or to a wrong
// offset now fails here.
test('the fixed-instant clock proves Africa/Nairobi is actually applied, not just a time-shaped string', async ({
  page,
}) => {
  await page.goto('/');
  await expect(page.getByTestId('nairobi-fixed')).toHaveText('19:30');
});
