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
    environment: 'node',
    include: ['src/**/*.test.ts'],
    // ONLY for this task, which adds the runner before it adds a test. Task 10 Step 3
    // DELETES this line in the same commit as the first test file. A permanently-passing
    // empty suite is the exact failure mode the Global Constraints forbid, and leaving this
    // in would make `npm run verify` green over zero assertions for the rest of the wave.
    passWithNoTests: true,
  },
});
