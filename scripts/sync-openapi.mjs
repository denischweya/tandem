#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const API_REPO = process.env.TANDEM_API_PATH ?? resolve('../../backend');
const SOURCE = resolve(API_REPO, 'openapi.json');
const SPEC = resolve('openapi.json');
const OUT = resolve('src/types/api.ts');

if (!existsSync(SOURCE)) {
  console.error(`Cannot find ${SOURCE}.`);
  console.error(
    'Set TANDEM_API_PATH to the tandem-api checkout, or run "make openapi" there first.',
  );
  process.exit(1);
}

// Validate before touching the committed spec: a malformed source must never overwrite
// a known-good openapi.json. Without this check, a bad SOURCE gets copied over SPEC
// first and only then does openapi-typescript fail, leaving the repo's committed file
// corrupted even though the script's own exit code is correctly non-zero.
try {
  JSON.parse(readFileSync(SOURCE, 'utf8'));
} catch (error) {
  console.error(`${SOURCE} is not valid JSON: ${error.message}`);
  process.exit(1);
}

copyFileSync(SOURCE, SPEC);
mkdirSync(dirname(OUT), { recursive: true });

execFileSync('npx', ['openapi-typescript', SPEC, '-o', OUT], { stdio: 'inherit' });

console.log(`Generated ${OUT} from ${SOURCE}`);
