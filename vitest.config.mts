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
    // TASK 11: jsdom is now installed (devDependency) and every `.test.tsx` file gets it via
    // the per-file `// @vitest-environment jsdom` docblock below, NOT by flipping this
    // default. The default stays 'node' for every `.test.ts` handler/unit test, which is the
    // thing the comment this replaced was protecting: `src/server/handlers.ts` takes and
    // returns web-standard Request/Response and never imports next/headers, so giving those
    // tests a DOM they do not need would be a silent behaviour change in the other direction.
    //
    // MEASURED: `environmentMatchGlobs` - the config-level glob-to-environment mapping this
    // comment used to recommend - does not exist on Vitest 4.1.11's `InlineConfig` type (it
    // was a Vitest 0.x/1.x option, removed before this version; grepping the installed
    // package's own .d.ts files for the string turns up nothing). The per-file docblock
    // comment is what Vitest 4 actually supports for a one-off environment override instead,
    // and every `.test.tsx` component test this task adds (src/components/ui/*.test.tsx,
    // src/components/auth/*.test.tsx) opens with `// @vitest-environment jsdom` and renders
    // real DOM nodes with `@testing-library/react` - which only resolves `document` because
    // of that comment. The task report records the break/restore proof that removing the
    // comment makes the exact same file fail loudly (`ReferenceError: document is not
    // defined`) rather than being silently skipped.
    environment: 'node',
    // `{ts,tsx}`, not `ts`. Task 11 writes four form components, and a LoginForm.test.tsx
    // under a `.test.ts`-only glob is not reported as skipped - it is never collected at
    // all, so the suite goes green and the count simply never mentions it. Task 10's handler
    // tests are `.test.ts`, so deleting passWithNoTests does not cover this on its own.
    // src/architecture/runner.test.ts pins this glob so a narrowing fails loudly.
    include: ['src/**/*.test.{ts,tsx}'],
    // Registers `@testing-library/jest-dom`'s matchers and an `afterEach(cleanup)` for every
    // test file, regardless of environment - see src/test/setup.ts's own docblock for why
    // that is safe for the node-environment handler tests too (both halves are no-ops there).
    setupFiles: ['src/test/setup.ts'],
    // NO passWithNoTests. It was in this file for exactly as long as this repo had no test:
    // it makes `npm run test` - and therefore `npm run verify` - pass over zero assertions,
    // which is the failure mode the Global Constraints forbid, bounded only by a human
    // remembering to delete it. src/architecture/runner.test.ts is what replaced that bound
    // with a mechanical one: the runner now has a real test to run, so vitest exits non-zero
    // if the suite ever becomes empty again.
  },
});
