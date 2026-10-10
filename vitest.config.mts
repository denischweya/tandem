import { resolve } from 'node:path';

import { defineConfig } from 'vitest/config';

export default defineConfig({
  resolve: { alias: { '@': resolve(__dirname, './src') } },
  test: {
    // The BFF handlers take and return web-standard Request/Response and never import
    // next/headers, so they need no DOM and no Next request scope. See src/server/handlers.ts
    // for why: `cookies()` is async and throws outside a request scope (verified in the
    // installed Next 16.3.8 at node_modules/next/dist/server/web/spec-extension/adapters/
    // request-cookies.js:53 for the write path), which would make every handler test a
    // mock of the framework instead of a test of the handler.
    //
    // Task 11's four form components will need a DOM, and this line is the thing to change
    // then: neither jsdom nor happy-dom is installed, so a render test added under this
    // config fails at `document is not defined` rather than being quietly skipped. Install
    // one and set `environment: 'jsdom'` (or an `environmentMatchGlobs` entry) in the task
    // that writes the first render test, not before.
    environment: 'node',
    // `{ts,tsx}`, not `ts`. Task 11 writes four form components, and a LoginForm.test.tsx
    // under a `.test.ts`-only glob is not reported as skipped - it is never collected at
    // all, so the suite goes green and the count simply never mentions it. Task 10's handler
    // tests are `.test.ts`, so deleting passWithNoTests does not cover this on its own.
    // src/architecture/runner.test.ts pins this glob so a narrowing fails loudly.
    include: ['src/**/*.test.{ts,tsx}'],
    // NO passWithNoTests. It was in this file for exactly as long as this repo had no test:
    // it makes `npm run test` - and therefore `npm run verify` - pass over zero assertions,
    // which is the failure mode the Global Constraints forbid, bounded only by a human
    // remembering to delete it. src/architecture/runner.test.ts is what replaced that bound
    // with a mechanical one: the runner now has a real test to run, so vitest exits non-zero
    // if the suite ever becomes empty again.
  },
});
