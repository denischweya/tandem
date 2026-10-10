import { QueryClient } from '@tanstack/react-query';

/**
 * Mirrors `tandem-mobile/src/api/queryClient.ts`'s `createQueryClient`: `staleTime: 30_000`,
 * and no retry once an attempt has already come back as an auth failure.
 *
 * The auth check is duck-typed on a `status` field rather than an `instanceof` check against
 * a shared error class, because Task 10 builds no general data-fetching error type for the
 * web client - `useSession`'s own query function never throws at all (the BFF's
 * `/api/auth/session` always answers 200, mapping a dead access token to `{status:
 * 'expired'}` in the body rather than a thrown 401). This exists for the data-fetching hooks
 * Task 11 and later add, whose query functions are expected to throw an object carrying the
 * response `status` on a non-2xx - the same shape `fetch` plus a thin wrapper naturally
 * produces, so no particular error class needs to exist yet for this to be correct for them
 * when they arrive.
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
