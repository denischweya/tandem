import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * Not in Task 10's brief file list, but `env.ts`'s own docblock (given verbatim by that
 * brief) closes with "Both now agree, and this one is tested" - a claim that was false until
 * this file existed. Unlike `tandem-mobile/src/config/env.ts`'s `apiBaseUrl` (a module-load-
 * time `const`, which needs `require()` after mutating `process.env` because a static
 * `import` is hoisted above the mutation), this module's `apiBaseUrl` is a FUNCTION that reads
 * `process.env.API_BASE_URL` at CALL time - so a plain `import` plus `process.env` writes in
 * each test body is sufficient, with no `require` indirection needed.
 *
 * `vi.mock('server-only', …)` IS load-bearing here, and is not merely following
 * `handlers.test.ts`'s pattern for `./apiFetch`. MEASURED: the published `server-only`
 * package's entire runtime body (`node_modules/server-only/index.js`) is
 * `throw new Error("This module cannot be imported from a Client Component module...")` -
 * unconditionally. Next's webpack build substitutes its own vendored no-op for the bare
 * `server-only` specifier (`create-compiler-aliases.js`), so `env.ts` loads fine inside
 * `next build`/`next dev`; Vitest runs on Vite's plain Node resolution instead, with no such
 * alias, so an UNMOCKED `import './env'` throws that error at import time, before any
 * assertion runs. `handlers.test.ts` never hits this because it mocks `./apiFetch` wholesale
 * and so never loads the real `env.ts` either - but a test that exercises `apiBaseUrl` itself
 * has no such escape. This is a one-line, per-test-file fix; `vitest.config.mts` is unchanged
 * and stays off-limits per the brief's own file list.
 */
vi.mock('server-only', () => ({}));

const { apiBaseUrl } = await import('./env');
const ORIGINAL_API_BASE_URL = process.env.API_BASE_URL;

afterEach(() => {
  if (ORIGINAL_API_BASE_URL === undefined) delete process.env.API_BASE_URL;
  else process.env.API_BASE_URL = ORIGINAL_API_BASE_URL;
});

describe('apiBaseUrl', () => {
  it('falls back to localhost when nothing is configured', () => {
    delete process.env.API_BASE_URL;
    expect(apiBaseUrl()).toBe('http://localhost:8000/api/v1');
  });

  it('reads the configured value and strips its trailing slash', () => {
    process.env.API_BASE_URL = 'https://api.example/api/v1/';
    expect(apiBaseUrl()).toBe('https://api.example/api/v1');
  });

  it('falls back when the variable is set but EMPTY - the `??` defect this module closes', () => {
    // Review finding m6: the deleted src/lib/env.ts read `process.env.NEXT_PUBLIC_API_BASE_URL
    // ?? 'http://localhost:8000/api/v1'`, so `API_BASE_URL=` (set but empty) produced `''`
    // via `??` (which only falls through on null/undefined) and every fetch silently became
    // relative. The guard here is `typeof x === 'string' && x.length > 0`, not `??`.
    process.env.API_BASE_URL = '';
    expect(apiBaseUrl()).toBe('http://localhost:8000/api/v1');
  });

  it('re-reads process.env on every call, rather than freezing a value at module load', () => {
    // The one place tandem-web's and tandem-mobile's identically-named apiBaseUrl are not
    // mirrors: mobile's is a module-load-time const (Constants.expoConfig is available
    // synchronously and cannot change for the bundle's life); web's is a function because a
    // Next server deployment must be reconfigurable without a rebuild.
    delete process.env.API_BASE_URL;
    expect(apiBaseUrl()).toBe('http://localhost:8000/api/v1');
    process.env.API_BASE_URL = 'https://second-call.example/api/v1';
    expect(apiBaseUrl()).toBe('https://second-call.example/api/v1');
  });
});
