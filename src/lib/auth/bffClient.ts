import { z } from 'zod';
import type { Equivalent, Expect } from '@tandem/shared';

import {
  fieldErrorsFor,
  HOUSE_NETWORK_MESSAGE,
  userMessageFor,
  type ErrorEnvelope,
} from '@/server/errors';

/**
 * The browser's half of talking to `tandem-web`'s own `/api/auth/*` BFF - every one of the
 * four forms in this task posts through this module and nothing else, mirroring why
 * `src/server/apiFetch.ts` is the single outbound caller on the server side.
 *
 * Deliberately NOT `src/server/apiFetch.ts` or anything that imports it: that module (and
 * `src/server/env.ts`, which it depends on) is marked `server-only` for ADR 0004's reason -
 * the browser must never learn the real API's address or call it directly - and this file is
 * imported from `'use client'` components. What IS imported from the server tree is
 * `src/server/errors.ts`'s `userMessageFor`/`fieldErrorsFor`/`HOUSE_NETWORK_MESSAGE`, which
 * carry no `server-only` tag because they are pure string/record functions with no secret and
 * no network call - the brief's own "Consumes" list names exactly these two functions for
 * exactly this reason.
 *
 * WHY THOSE TWO FUNCTIONS ARE CALLED AGAIN HERE, not merely trusted from the BFF's own JSON
 * body: `relayFailure` (handlers.ts) already ran `userMessageFor`/`fieldErrorsFor` server-side
 * to build `{message, errors?}` before this ever reaches the browser, so re-running the same
 * allowlist gate client-side is redundant ON THE HAPPY PATH - but it is exactly the belt-and-
 * suspenders this wave has needed four times already in `src/server/errors.ts` alone (see
 * that file's own "FIX ROUND 3" docblock comparing `userMessageFor` and `fieldErrorsFor` side
 * by side): the same decision made in one place and not its sibling. If a future change to
 * `relayFailure` ever let raw backend text leak into `message` or `errors` for an
 * unauthored status, this client-side re-application of the identical allowlist is a second,
 * independent gate that still substitutes house copy before anything reaches JSX - not a
 * trust boundary this file is allowed to skip just because the server "already decided".
 */

/**
 * The BFF's own failure body, bare strings per field - MEASURED in
 * `src/server/handlers.test.ts`'s "the `errors` field has ONE shape across every producer on
 * this route" describe block: `errors.email` is asserted `typeof ... === 'string'`, not an
 * array, across all three producers (local Zod validation, a backend-sourced 422, and the
 * dead-reset-token 422). `tsc` cannot pin this on its own - `jsonResponse(status, body)` in
 * handlers.ts takes `body: unknown` - so this schema is this file's own runtime guard against
 * that shape ever regressing to `string[]`, same as the server-side test. Written as
 * `z.record(z.string(), z.string())`, NOT `z.array(z.string())` anywhere - loosening it back
 * to accept an array would silently defeat the one guard `tsc` cannot provide.
 */
const BffFailureBodySchema = z.object({
  message: z.string(),
  errors: z.record(z.string(), z.string()).optional(),
});

/**
 * Structural pin, independent of the runtime test above: this schema's `errors` type must
 * describe the exact same wire shape `fieldErrorsFor` (src/server/errors.ts) actually returns
 * - `Record<string, string>`, not `Record<string, string[]>`. `Equivalent` compares both
 * directions, so EITHER a widening (back to arrays) or a narrowing of either side fails to
 * compile. This does not replace the runtime test above - a schema can agree with
 * `fieldErrorsFor`'s TYPE while the real server response disagrees with both - it closes the
 * other half: if `fieldErrorsFor`'s own return type ever changes, this file is told by `tsc`
 * rather than by a form silently failing to read a field error at runtime.
 */
export type _ClientErrorsMatchFieldErrorsForShape = Expect<
  Equivalent<
    NonNullable<z.infer<typeof BffFailureBodySchema>['errors']>,
    NonNullable<ReturnType<typeof fieldErrorsFor>>
  >
>;

function toEnvelope(body: z.infer<typeof BffFailureBodySchema>): ErrorEnvelope {
  if (body.errors === undefined) return { message: body.message };
  const errors: Record<string, string[]> = {};
  for (const [field, text] of Object.entries(body.errors)) errors[field] = [text];
  return { message: body.message, errors };
}

export interface BffFailure {
  readonly status: number;
  /** Always safe to render - house copy, or an authored sentence `userMessageFor` already
   *  allowlisted, with a 429's `Retry-After` suffix already appended when one was present. */
  readonly message: string;
  readonly fieldErrors: Record<string, string> | null;
}

/** `status: 0` - never a real HTTP status - marks the two cases that never got a response at
 *  all: a transport failure, or a malformed/absent body from an `ok` response this file still
 *  needs to report on (neither should occur against this app's own same-origin BFF, but both
 *  get house copy rather than a crash). */
function houseFailure(message: string): BffFailure {
  return { status: 0, message, fieldErrors: null };
}

/**
 * §8.2: login's and register's and forgot-password's rate limits are not shown as a remaining-
 * attempts count (the budget counts failures and a success clears it, so any number shown
 * would be wrong the instant the next attempt succeeds) - the ONLY thing `Retry-After` earns a
 * user is "try again in N minutes". Mirrors `tandem-mobile/src/features/auth/screenLogic.ts`'s
 * `retryAfterSuffix` exactly (same rounding, same copy), because the EACH mobile and this BFF
 * only ever answer with a whole-seconds `Retry-After` header - the same number, rounded the
 * same way, reads as the same promise on both clients.
 */
export function retryAfterSuffix(seconds: number): string {
  const minutes = Math.max(1, Math.ceil(seconds / 60));
  return ` Try again in ${minutes} minute${minutes === 1 ? '' : 's'}.`;
}

async function describeFailureResponse(response: Response): Promise<BffFailure> {
  let json: unknown = null;
  try {
    json = await response.json();
  } catch {
    json = null;
  }
  const parsed = BffFailureBodySchema.safeParse(json);
  const envelope = parsed.success ? toEnvelope(parsed.data) : null;

  let message = userMessageFor(response.status, envelope);
  const retryAfterHeader = response.headers.get('retry-after');
  if (retryAfterHeader !== null) {
    const retryAfterSeconds = Number.parseInt(retryAfterHeader, 10);
    if (!Number.isNaN(retryAfterSeconds)) message += retryAfterSuffix(retryAfterSeconds);
  }

  return {
    status: response.status,
    message,
    fieldErrors: fieldErrorsFor(response.status, envelope),
  };
}

export type BffResult =
  | { readonly kind: 'ok'; readonly status: number; readonly json: unknown }
  | { readonly kind: 'failure'; readonly failure: BffFailure };

/**
 * The one `fetch` call site for every auth form. `init` is passed through verbatim - a bare
 * `{method: 'POST'}` for `/auth/verify-email` (no body: the real API's four parameters are
 * query parameters, and `verifyEmailHandler` validates nothing else), or `postJson`'s
 * JSON-body form for the other three endpoints.
 *
 * No `credentials` option: a same-origin `fetch` from a page served by this same Next app
 * already sends same-origin cookies by default, and ADR 0004's whole point is that these
 * requests go to THIS origin's `/api/auth/*` routes, never to the real API directly - so
 * there is no cross-origin case here needing `credentials: 'include'`.
 */
export async function submitBff(path: string, init: RequestInit): Promise<BffResult> {
  let response: Response;
  try {
    response = await fetch(path, init);
  } catch {
    // A transport failure between THIS BROWSER and the Next server that served it its own
    // page - distinct from (and far rarer than) `src/server/apiFetch.ts`'s transportFailure,
    // which is the BFF failing to reach the real API. Same house copy either way: neither is
    // evidence of anything about the user's credentials, and `HOUSE_NETWORK_MESSAGE` is
    // exactly the sentence written for "never reached the server at all".
    return { kind: 'failure', failure: houseFailure(HOUSE_NETWORK_MESSAGE) };
  }

  if (!response.ok) {
    return { kind: 'failure', failure: await describeFailureResponse(response) };
  }

  let json: unknown = null;
  try {
    json = await response.json();
  } catch {
    // 204 (reset-password) and 202 (forgot-password) both answer with no body at all -
    // `.json()` on an empty body throws, and `json` staying `null` is the correct reading for
    // both, not an error: `response.ok` is already true.
    json = null;
  }
  return { kind: 'ok', status: response.status, json };
}

/** JSON-body POST to one of this app's own `/api/auth/*` routes. */
export function postJson(path: string, body: unknown): Promise<BffResult> {
  return submitBff(path, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(body),
  });
}
