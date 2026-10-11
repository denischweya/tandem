import { QueryClient } from '@tanstack/react-query';

/**
 * Mirrors `tandem-mobile/src/api/queryClient.ts`'s `createQueryClient`: `staleTime: 30_000`,
 * and no retry once an attempt has already come back as an auth failure.
 *
 * The auth check is duck-typed on a `status` field rather than an `instanceof` check against
 * a shared error class, because Task 10 builds no general data-fetching error type for the
 * web client.
 *
 * FIX ROUND 2, M3: this used to say "useSession's own query function never throws at all (the
 * BFF's /api/auth/session always answers 200 …)". That stopped being true in THIS task, not
 * a later one: fix round 2, Important 3's second half made `sessionHandler` answer a non-401
 * error (or a transport failure) with a non-2xx status and a body with no `status` field,
 * and `useSession.ts`'s `fetchSession` throws a `SessionFetchError` (carrying `.status`)
 * before ever reaching `SessionResultSchema.parse` for exactly that case - see its own
 * docblock. So `isAuthFailure` is load-bearing for `useSession` ITSELF now, not merely
 * speculative groundwork for data-fetching hooks Task 11 and later add - though it still
 * needs to stay correct for those too, which is why the check stays duck-typed rather than an
 * `instanceof SessionFetchError` that would tie this module to one caller's error class.
 */
function isAuthFailure(error: unknown): boolean {
  if (typeof error !== 'object' || error === null || !('status' in error)) return false;
  const { status } = error as { status: unknown };
  return status === 401 || status === 403;
}

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        retry: (failureCount, error) => {
          if (isAuthFailure(error)) return false;
          return failureCount < 2;
        },
      },
    },
  });
}
