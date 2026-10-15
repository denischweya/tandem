import { resolve } from 'node:path';

import { defineConfig } from 'vitest/config';

/**
 * TASK 12's toolchain guard. `.nvmrc` pins Node 22, but this machine's default (and CI's,
 * absent an explicit version step) is Node 20.18.2 - and `npm run test` against THIS repo's
 * installed jsdom 30.1.2 under Node 20.18.2 is not a clean failure. MEASURED (2026-10-16,
 * `npm run test` run directly under Node 20.18.2, output pasted verbatim in the task report):
 * every `.test.tsx` worker crashes with `ERR_REQUIRE_ESM` from `html-encoding-sniffer`'s
 * `require()` of `@exodus/bytes`'s ESM build, vitest reports those 13 crashes as bare
 * "Unhandled Error"s rather than failed test files, and the summary reads
 * `Test Files  14 passed (14)` - 14/14, not 14/27, because the 13 `.tsx` files that never
 * started are silently absent from the denominator rather than counted as failing. The
 * process DOES exit non-zero on this specific jsdom/Node pairing (`Errors  13 errors` prints
 * above that summary), which is louder than this guard's own docblock first assumed - but the
 * diagnosis a reader actually sees is "`require() of ES Module ... not supported`" four
 * layers deep in a transitive dependency, not "wrong Node version." `package.json`'s
 * `engines` field is not enough on its own either: `npm install` only WARNS on a mismatch, it
 * does not fail the run that matters (`npm run test`/`npm run verify`), so a 20.x install
 * still reaches this exact point having been told nothing was wrong. This check turns that
 * four-layers-deep crash into the one line actually worth reading, as early as this config
 * file loads - before vitest spawns a single worker.
 */
const MIN_SUPPORTED_NODE_MAJOR = 22;
const [installedNodeMajor] = process.versions.node.split('.').map(Number);
if (installedNodeMajor === undefined || installedNodeMajor < MIN_SUPPORTED_NODE_MAJOR) {
  throw new Error(
    `tandem-web's test suite requires Node ${MIN_SUPPORTED_NODE_MAJOR}+ (see .nvmrc); this ` +
      `is Node ${process.versions.node}. On Node < ${MIN_SUPPORTED_NODE_MAJOR} every ` +
      `*.test.tsx worker crashes loading jsdom (ERR_REQUIRE_ESM from html-encoding-sniffer -> ` +
      `@exodus/bytes), and vitest's summary silently drops those files from its total instead ` +
      `of counting them as failed - "Test Files 14 passed (14)" when 13 more never ran. Run ` +
      `\`nvm use ${MIN_SUPPORTED_NODE_MAJOR}\` (matching .nvmrc) and try again.`,
  );
}

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
