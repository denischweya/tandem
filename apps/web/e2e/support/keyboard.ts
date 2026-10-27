import type { Page } from '@playwright/test';

/**
 * Lifted out of `e2e/invite.spec.ts`, where it was originally written to exercise
 * `RsvpResponse`'s three choice buttons. Task 12 re-points it at `InviteActions`'s two
 * actions instead.
 *
 * FIX ROUND 1 (reviewer Minor 1): this docblock used to claim re-pointing it made it "a
 * second caller of the identical helper, which is why it now lives here" - false today.
 * `e2e/invite.spec.ts` is the only importer, and `e2e/live/` does not exist yet. The
 * extraction itself is still correct - the brief (Step 4) mandated lifting it ahead of Task
 * 13, which adds `e2e/live/invite.spec.ts` as the second caller - but the claim is about the
 * future, not the present: this module is pre-positioned for `e2e/live/invite.spec.ts`
 * (Task 13), not already shared between two files. A docblock asserting a caller that does
 * not exist is a false measured claim the next reader would take at face value.
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
