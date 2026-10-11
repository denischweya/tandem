'use client';

import { createElement, useEffect, useState, type ReactNode } from 'react';
import {
  QueryClientProvider,
  useQuery,
  useQueryClient,
  type UseQueryResult,
} from '@tanstack/react-query';
import { UserSchema } from '@tandem/shared';
import { z } from 'zod';

import { createQueryClient } from './queryClient';
import { refreshSession } from './refresh-client';

/**
 * Named `.ts`, not `.tsx`, per the brief's own file list - `SessionProvider` therefore uses
 * `createElement` rather than JSX syntax, since a plain `.ts` file is never scanned for JSX
 * (`<Foo>` there parses as a type assertion, not an element).
 */

const SessionResultSchema = z.discriminatedUnion('status', [
  z.object({ status: z.literal('signedIn'), user: UserSchema }),
  z.object({ status: z.literal('expired') }),
  z.object({ status: z.literal('signedOut') }),
]);

export type SessionResult = z.infer<typeof SessionResultSchema>;

export const SESSION_QUERY_KEY = ['session'] as const;

/**
 * Fix round 1, Important 3's client-side half: `sessionHandler` now answers a non-401 error
 * or a transport failure with a non-2xx status and a body that has no `status` field at all
 * (there is no fourth state in `SessionResultSchema`'s three-member discriminated union) -
 * deliberately, so this never gets read as `expired` and never triggers `refreshSession()`.
 * Thrown with a `status` property so `queryClient.ts`'s duck-typed `isAuthFailure` can tell a
 * 401/403 (give up immediately) from a 500/503 (worth `createQueryClient`'s normal retry).
 */
export class SessionFetchError extends Error {
  constructor(readonly status: number) {
    super(`HTTP ${status}`);
    this.name = 'SessionFetchError';
  }
}

async function fetchSession(): Promise<SessionResult> {
  const response = await fetch('/api/auth/session');
  if (!response.ok) throw new SessionFetchError(response.status);
  const json: unknown = await response.json();
  return SessionResultSchema.parse(json);
}

/**
 * Owns the one `QueryClient` this app's session state lives in. Created once per mount
 * (`useState(() => …)`, never recreated on a re-render) rather than as a module-level
 * singleton, which is what keeps this safe under React's Strict Mode double-invoke and under
 * a future server-rendered provider tree, where a module singleton would leak one request's
 * cache into the next.
 */
export function SessionProvider({ children }: { children: ReactNode }): ReactNode {
  const [client] = useState(() => createQueryClient());
  return createElement(QueryClientProvider, { client }, children);
}

/**
 * Reads `GET /api/auth/session`, which never throws for a dead ACCESS TOKEN specifically -
 * a 401 from `/me` answers 200 with `{status: 'expired'}` - so there is no error branch to
 * handle here for that one case. A non-401 error or a transport failure DOES throw
 * (`SessionFetchError`, from `fetchSession` above) and is left to react-query's own retry and
 * error state; this hook does not catch it and does not call `refreshSession()` for it.
 *
 * On `{status: 'expired'}` this calls `refreshSession()` EXACTLY ONCE (the effect's own
 * dependency array keys off `query.data?.status`, so a second render with the same status
 * does not fire a second attempt) and then either invalidates the session query (on a
 * successful refresh, picking up the new `signedIn` state) or writes `signedOut` directly
 * (on failure) rather than refetching.
 *
 * FIX ROUND 2, M3 (and corrected again in round 3 - the prior correction itself undercounted):
 * this used to justify the direct `signedOut` write with "the BFF already cleared both
 * cookies on that failure, so a refetch would land on signedOut anyway" - true ONLY for
 * `refreshSession()` resolving `false` via a 401 (round 2's Important 3: `refreshHandler`
 * clears cookies on a 401 and ONLY a 401). There are THREE other ways `refreshSession()`
 * resolves `false`, not two as round 2's correction said - the omitted one is the LARGEST
 * class and the one round 1's Important 3 fix deliberately made non-terminal:
 *   1. a non-401 error status (429, 500, 503) from `refreshHandler` - cookies deliberately
 *      left untouched, because the refresh token itself was never examined;
 *   2. a transport failure relayed as a bare 502 - cookies also untouched, for the same
 *      reason a network blip is never evidence the credential is bad;
 *   3. a timed-out follower's session read coming back non-`signedIn` (round 2/3's
 *      Important I-C) - which may mean the leader is still working, not that it failed, and
 *      touches no cookie at all either way.
 * In all three, this still writes `signedOut` into the cache while both cookies may well be
 * intact - a known, accepted rough edge of reading one `boolean` back from `refreshSession()`
 * rather than the richer outcome it discards, not fixed here because the alternative (refetch
 * `/api/auth/session` instead of guessing) risks an infinite retry loop against a genuinely
 * down API, which `refreshed === false` -> `signedOut` at least bounds.
 */
export function useSession(): UseQueryResult<SessionResult> {
  const queryClient = useQueryClient();
  const query = useQuery({ queryKey: SESSION_QUERY_KEY, queryFn: fetchSession });

  useEffect(() => {
    if (query.data?.status !== 'expired') return;

    let cancelled = false;
    void refreshSession().then((refreshed) => {
      if (cancelled) return;
      if (refreshed) {
        void queryClient.invalidateQueries({ queryKey: SESSION_QUERY_KEY });
      } else {
        queryClient.setQueryData<SessionResult>(SESSION_QUERY_KEY, { status: 'signedOut' });
      }
    });

    return () => {
      cancelled = true;
    };
  }, [query.data?.status, queryClient]);

  return query;
}
