import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

import { describe, expect, it } from 'vitest';

/**
 * `tandem-web`'s first real architecture gate (Wave 1b final review, W4/3b.1).
 *
 * BEFORE THIS FILE, `src/architecture/` held exactly one test - `runner.test.ts` (now moved
 * to `src/test/`, where its own job, a toolchain sentinel, belongs) - and nothing else in
 * this directory, or anywhere in this repo, mechanically checked an architecture property.
 * "The directory name `src/architecture/` in web is the single most misleading thing across
 * both repos" (`gate-coverage.md` §3): a reader reasoning "both clients have architecture
 * gates because both have a directory called that" was wrong.
 *
 * SCOPE, DELIBERATELY NARROW. The review's own gate analysis proposed FOUR rows for a
 * "minimum set" (env/NEXT_PUBLIC_ ownership, a ban on browser storage, a browser-fetch-target
 * check, and a route-manifest/filesystem equality). The controller for this fix wave scoped
 * this file to exactly ONE of those - the class that was ACTUALLY fixed on this branch, not
 * a port of mobile's eight-gate suite: Task 11 deleted `src/lib/env.ts` because it read
 * `NEXT_PUBLIC_API_BASE_URL`, which Next inlines into the client bundle - under ADR 0004 the
 * browser must never learn the real API's address, and a `NEXT_PUBLIC_*` read anywhere
 * advertises exactly that. Nothing stopped a future implementer re-adding one, which is the
 * precise test for a merge condition the final review used: "a known-expensive bug that can
 * return silently."
 *
 * The other three rows are NOT ported here. Row 2 (no `localStorage`/`sessionStorage`/
 * `document.cookie`/`indexedDB` anywhere in `src/` - currently zero occurrences, and the
 * mechanical form of blueprint §91/§154's "no secret in localStorage") reads as the next
 * cheapest, highest-value candidate if a future wave wants a second gate - see the fix
 * report for this wave's own note to that effect. Rows 3 and 4 need either caller-tracing
 * (fetch targets) or a filesystem/manifest comparison (the eight `route.ts` files) that are
 * reasonable work for a wave that has room to prove them properly, not this one.
 */

const SOURCE_ROOTS = ['src', 'app'] as const;
const ROOT = join(__dirname, '..', '..');

function treeFiles(): string[] {
  const out: string[] = [];
  const walk = (dir: string): void => {
    for (const entry of readdirSync(dir)) {
      const full = join(dir, entry);
      if (statSync(full).isDirectory()) {
        walk(full);
        continue;
      }
      if (/\.tsx?$/.test(entry)) out.push(full);
    }
  };
  for (const root of SOURCE_ROOTS) {
    try {
      walk(join(ROOT, root));
    } catch {
      // `app/` does not exist at the repo root in every checkout shape; `src/` always does.
      // A missing root is not itself a violation of anything this file checks.
    }
  }
  return out.map((full) => relative(ROOT, full).split('\\').join('/'));
}

function readRepoFile(path: string): string {
  return readFileSync(join(ROOT, path), 'utf8');
}

describe('env ownership (the NEXT_PUBLIC_ / process.env boundary)', () => {
  it('finds a non-trivial tree to scan', () => {
    // The sentinel every tree-wide gate in this file needs: a wrong `ROOT`/`SOURCE_ROOTS`
    // makes every assertion below pass over an empty list. Measured at the time this file
    // was written: `src/` alone holds comfortably over 60 `.ts`/`.tsx` files.
    expect(treeFiles().length).toBeGreaterThan(40);
  });

  /**
   * MEASURED today: `process.env` appears in exactly `src/server/env.ts` and its own test,
   * plus `src/server/apiFetch.test.ts` (which stubs `process.env.API_BASE_URL` to exercise
   * `apiFetch.ts`'s own call into `apiBaseUrl()`) - nowhere else. Test files are excluded
   * from the PRODUCTION half of this check (a test stubbing an env var to exercise the one
   * file allowed to read it is not the class this guards against), but the owner file itself
   * is named explicitly rather than inferred, so a reader sees the one approved production
   * reader without having to trust a negative.
   */
  it('reads process.env only in src/server/env.ts, among production (non-test) files', () => {
    const offenders = treeFiles()
      .filter((path) => !/\.test\.tsx?$/.test(path))
      .filter((path) => path !== 'src/server/env.ts')
      .filter((path) => readRepoFile(path).includes('process.env'));
    expect(offenders).toEqual([]);
  });

  /**
   * THE CLASS THIS FILE EXISTS FOR. `NEXT_PUBLIC_` is Next's own inlining prefix - any
   * environment variable named with it is baked into the CLIENT bundle at build time,
   * readable by anyone who opens devtools, regardless of which file reads it or whether that
   * file ever runs server-side. MEASURED today: the identifier appears only in
   * `src/server/env.ts` and `src/server/env.test.ts`, both as PROSE describing the deleted
   * `src/lib/env.ts` (review finding m6) - not as a live `process.env.NEXT_PUBLIC_*` read.
   * Scoped to those two files by name, not by "test vs. production", because the owner
   * file's own historical-context comment is exactly as legitimate a carrier as its test.
   */
  it('names NEXT_PUBLIC_ nowhere outside src/server/env.ts and its own test', () => {
    // This file itself is a fourth, necessary exception: its own docblock has to NAME the
    // class it guards against in prose, the same way env.ts's does.
    const ALLOWED = new Set([
      'src/server/env.ts',
      'src/server/env.test.ts',
      'src/architecture/envOwnership.test.ts',
    ]);
    const offenders = treeFiles()
      .filter((path) => !ALLOWED.has(path))
      .filter((path) => readRepoFile(path).includes('NEXT_PUBLIC'));
    expect(offenders).toEqual([]);
  });
});
