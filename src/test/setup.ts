import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';

/**
 * Runs for EVERY test file, regardless of environment - `setupFiles` has no per-environment
 * variant in Vitest 4 (see vitest.config.mts's own note on why `environmentMatchGlobs` is not
 * an option on this version). That is fine for both halves of what this file does:
 *
 *   - `@testing-library/jest-dom/vitest` only calls `expect.extend(...)`, which needs no DOM
 *     to register and is simply never exercised by a `.test.ts` handler test that renders
 *     nothing.
 *   - `cleanup()` unmounts whatever `@testing-library/react` tracked as rendered THIS test -
 *     an empty set in a node-environment file that never called `render()` at all, so this
 *     is a no-op there and the one thing that stops jsdom's `document.body` accumulating a
 *     new tree per test within the SAME `.test.tsx` file (measured: without this, a file
 *     with six `render()` calls has the label text from all six still in `document.body` by
 *     the sixth test, and `getByLabelText` starts throwing "multiple elements found").
 */
afterEach(() => {
  cleanup();
});
