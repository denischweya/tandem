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

async function fetchSession(): Promise<SessionResult> {
  const response = await fetch('/api/auth/session');
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
 * Reads `GET /api/auth/session`, which never throws on a dead credential - it answers 200
 * with `{status: 'expired'}` - so there is no error branch to handle here for that case.
 *
 * On `{status: 'expired'}` this calls `refreshSession()` EXACTLY ONCE (the effect's own
 * dependency array keys off `query.data?.status`, so a second render with the same status
 * does not fire a second attempt) and then either invalidates the session query (on a
 * successful refresh, picking up the new `signedIn` state) or writes `signedOut` directly
 * (on failure) rather than refetching - the BFF already cleared both cookies on that failure,
 * so a refetch of `/api/auth/session` would land on `signedOut` anyway, one round trip later.
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
