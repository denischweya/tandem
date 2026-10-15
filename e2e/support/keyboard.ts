import type { Page } from '@playwright/test';

/**
 * Lifted out of `e2e/invite.spec.ts`, where it was originally written to exercise
 * `RsvpResponse`'s three choice buttons. Task 12 re-points it at `InviteActions`'s two
 * actions instead - a second caller of the identical helper, which is why it now lives here
 * rather than being copy-pasted into a second spec file (global constraint: "a helper used by
 * two test files goes in one shared module" - `e2e/support/` for web e2e).
 */

/** Tabs forward until the element with this `data-testid` has focus. */
export async function tabUntilFocused(page: Page, testId: string, maxPresses = 20): Promise<void> {
  for (let i = 0; i < maxPresses; i += 1) {
    await page.keyboard.press('Tab');
    const focused = await page.evaluate(
      (id) => document.activeElement?.getAttribute('data-testid') === id,
      testId,
    );
    if (focused) return;
  }
  throw new Error(`Could not reach [data-testid="${testId}"] by pressing Tab ${maxPresses} times`);
}
