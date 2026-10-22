import { randomUUID } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

/**
 * A fresh address per use. `users.email` is UNIQUE under a lowercase CHECK, so a reused
 * address is a 422 on the second run and the suite becomes order-dependent. The '+' tag keeps
 * every account in one inbox namespace for Mailpit queries.
 */
export function uniqueEmail(prefix: string): string {
  return `${prefix}+${Date.now()}-${randomUUID().slice(0, 8)}@tandem.test`;
}

/** 21 characters, clearing the API's `Password::min(12)` with no composition rules to
 *  satisfy - there deliberately are none upstream (NIST SP 800-63B). */
export const STRONG_PASSWORD = 'correct-horse-battery';

/** The password `live/auth.spec.ts`'s AC 3 test rotates to. Also 21 characters, also no
 *  composition rules. It is written back into the account file below, which is why the
 *  sign-in tests read the password from there rather than importing `STRONG_PASSWORD`. */
export const ROTATED_PASSWORD = 'tandem-live-rotated-1';

export const ACCOUNT_FILE = 'e2e/.auth/account.json';
export const STORAGE_STATE_FILE = 'e2e/.auth/user.json';

export interface LiveAccount {
  readonly email: string;
  readonly displayName: string;
  /** The password that works RIGHT NOW. `auth.spec.ts`'s reset test rewrites this field,
   *  because a reset genuinely invalidates the old one and a suite that kept importing a
   *  constant would be asserting against a credential the server has already replaced. */
  readonly password: string;
}

/**
 * The setup project's account, passed to the live specs through a FILE and not through
 * `process.env`.
 *
 * The brief's own Step 3 snippet ended with `process.env.TANDEM_E2E_EMAIL = email`, and then
 * said in prose that this does not work: Playwright runs each project in its own worker
 * process, so an assignment in the setup project is invisible to the `live` project. The
 * brief told the implementer to "pick one and do it"; this is the file, written next to the
 * storage state the `live` project already loads from the same directory.
 */
export function saveAccount(account: LiveAccount): void {
  mkdirSync(dirname(ACCOUNT_FILE), { recursive: true });
  writeFileSync(ACCOUNT_FILE, `${JSON.stringify(account, null, 2)}\n`, 'utf8');
}

export function loadAccount(): LiveAccount {
  const raw = readFileSync(ACCOUNT_FILE, 'utf8');
  const parsed: unknown = JSON.parse(raw);
  if (
    typeof parsed !== 'object' ||
    parsed === null ||
    typeof (parsed as LiveAccount).email !== 'string' ||
    typeof (parsed as LiveAccount).displayName !== 'string' ||
    typeof (parsed as LiveAccount).password !== 'string'
  ) {
    throw new Error(`${ACCOUNT_FILE} does not hold a {email, displayName, password} object`);
  }
  return parsed as LiveAccount;
}

/**
 * An access-cookie value the API is guaranteed to reject with 401, in the exact shape a real
 * one has.
 *
 * MEASURED, and it is why no live test ever *clears* `tandem_at` to simulate expiry:
 * `getSession()` and `sessionHandler` both answer `signedOut` when the access cookie is
 * ABSENT (`if (access === null) return {status: 'signedOut'}`), and `signedOut` redirects to
 * `/signin` - it never reaches `expired`, so it never reaches `refreshSession()` at all. The
 * brief's AC 11 snippet used `clearCookies({name: 'tandem_at'})` and would therefore have
 * asserted against the sign-in page it was written to prove does NOT appear. An access token
 * that has EXPIRED is still present and still sent; what changes is that `GET /me` answers
 * 401. That is what this value reproduces.
 *
 * The shape is Sanctum's `<token-uuid>|<48 chars>` (see `src/server/apiFetch.ts`), so the
 * API's own token parsing runs on it rather than being short-circuited by something that
 * could never be a token. Measured against the live API: 401, with `accept: application/json`
 * as the BFF always sends.
 */
export const REJECTED_ACCESS_TOKEN = '01a16493-0000-7000-8000-000000000000|' + 'z'.repeat(48);
