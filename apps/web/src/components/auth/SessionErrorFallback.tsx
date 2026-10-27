'use client';

import { useRouter } from 'next/navigation';

import { ErrorState } from '@/components/ui/ErrorState';

/**
 * `/plans`'s handling of `getSession()` (src/server/session.ts) throwing `SessionReadError` -
 * a non-401 error status, a transport failure, or a 2xx body that failed to parse. That
 * function's own docblock names an `error.tsx` boundary that does not exist anywhere under
 * `src/app`, and says whichever task gives `getSession()` its first production caller is
 * where that gets resolved - one way or the other, not left stated and absent. This task
 * resolves it by handling the error AT THE CALL SITE (`/plans/page.tsx`'s own try/catch)
 * rather than adding a route-tree-wide `error.tsx`: a single Server Component page function is
 * already the right granularity for "this one read failed, offer to retry it", and a global
 * boundary would catch render errors from pages that have nothing to do with a session read.
 *
 * `message` is always `HOUSE_SERVER_MESSAGE` (src/server/errors.ts) - `SessionReadError`
 * carries only a numeric `.status`, and §135 forbids turning that into anything derived from
 * the raw backend response.
 */
export function SessionErrorFallback({ message }: { readonly message: string }) {
  const router = useRouter();
  return (
    <ErrorState
      title="Something went wrong"
      body={message}
      action={{ label: 'Try again', onClick: () => router.refresh() }}
    />
  );
}
