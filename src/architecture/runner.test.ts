import { resolve } from 'node:path';
import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

import { joinWithAnd } from '@/lib/format-list';

/**
 * The web test runner's own regression test.
 *
 * This repo had no unit-test runner before Wave 1b, so `npm run test` was added to `verify`
 * before anything existed for it to run. The first version of vitest.config.mts therefore
 * carried `passWithNoTests: true`, which made that step report green over zero assertions -
 * the exact shape of guard this project has already shipped green three times - bounded only
 * by a later task remembering to remove it. This file is the mechanical bound instead: the
 * runner always has real work, so an empty suite is an error rather than a pass.
 */
describe('the web test runner', () => {
  it('executes a real assertion', () => {
    expect(joinWithAnd([])).toBe('');
    expect(joinWithAnd(['Ada'])).toBe('Ada');
    expect(joinWithAnd(['Ada', 'Bo'])).toBe('Ada and Bo');
  });

  it('resolves the @ alias declared in vitest.config.mts', () => {
    // The import at the top of this file IS the assertion - `@/lib/format-list` is an alias
    // that only vitest.config.mts's `resolve.alias` provides, so a broken or dropped alias
    // fails this suite at import time rather than at this line. Measured during Task 1: a
    // wrong export name produced "TypeError: … is not a function" and not a resolution
    // error, which is how the alias was confirmed to resolve rather than assumed to.
    expect(typeof joinWithAnd).toBe('function');
  });

  it('is configured to collect .tsx tests, which Task 11 needs', () => {
    // Read as text rather than imported: importing vitest.config.mts from a .ts file needs
    // `allowImportingTsExtensions`, which this repo does not set.
    //
    // Narrowing `include` back to 'src/**/*.test.ts' would not fail anything else - a
    // LoginForm.test.tsx is simply never collected, and the suite stays green while the form
    // it tests goes unverified. This is the assertion that makes that narrowing loud.
    const config = readFileSync(resolve(process.cwd(), 'vitest.config.mts'), 'utf8');
    expect(config).toContain("include: ['src/**/*.test.{ts,tsx}']");
  });
});
