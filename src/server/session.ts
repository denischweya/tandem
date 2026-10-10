import 'server-only';

import { headers } from 'next/headers';
import { UserSchema } from '@tandem/shared';
import { z } from 'zod';

import { callApi } from './apiFetch';
import { readSessionCookies } from './cookies';

export type SessionReadResult =
  | { readonly status: 'signedIn'; readonly user: z.infer<typeof UserSchema> }
  | { readonly status: 'expired' }
  | { readonly status: 'signedOut' };

function parseUser(json: unknown): z.infer<typeof UserSchema> | null {
  const parsed = z.object({ data: UserSchema }).safeParse(json);
  return parsed.success ? parsed.data.data : null;
}

/**
 * The read used from a Server Component render - `src/app/api/auth/session/route.ts`'s
 * `sessionHandler` is the one a CLIENT component calls over `fetch`, and this is the server-
 * side equivalent for a component that renders before any browser JavaScript runs.
 *
 * Reads the raw `Cookie` header through `next/headers`'s `headers()` (read-only, always legal
 * in a Server Component) and `readSessionCookies` - the exact parser `cookies.test.ts`
 * already proves - rather than `next/headers`'s `cookies()` jar. Either would work for a
 * READ; this one is chosen so the same tested decoding handles both the handler path and this
 * one, and so this module touches nothing that write-restricted `cookies()` guards.
 *
 * A SERVER COMPONENT CANNOT REFRESH (decision 2: the browser is the only refresh initiator),
 * so this never attempts one. `{status: 'expired'}` is the signal a page acts on by rendering
 * a small client component that calls `refreshSession()` and then `router.refresh()` - one
 * extra round trip on an expired access token, and zero cookie writes during a render.
 */
export async function getSession(): Promise<SessionReadResult> {
  const headerBag = await headers();
  const { access } = readSessionCookies(headerBag.get('cookie'));
  if (access === null) return { status: 'signedOut' };

  const result = await callApi('/me', { method: 'GET', bearer: access });
  if (result.kind !== 'ok') return { status: 'expired' };

  const user = parseUser(result.json);
  if (user === null) return { status: 'expired' };

  return { status: 'signedIn', user };
}
