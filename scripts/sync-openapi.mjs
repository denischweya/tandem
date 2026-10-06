#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const API_REPO = process.env.TANDEM_API_PATH ?? resolve('../tandem-api');
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

copyFileSync(SOURCE, SPEC);
mkdirSync(dirname(OUT), { recursive: true });

execFileSync('npx', ['openapi-typescript', SPEC, '-o', OUT], { stdio: 'inherit' });

console.log(`Generated ${OUT} from ${SOURCE}`);
