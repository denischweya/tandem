'use client';

import { useRouter } from 'next/navigation';
import { Button } from '@/components/ui/Button';

export interface SignupPromptProps {
  /**
   * The already-constructed `/invite/{token}` path to round-trip through `next=`. Carried
   * raw (not re-validated here) because the one sanctioned check on a caller-supplied
   * redirect target, `safeNextPath` (src/lib/auth/redirect.ts), runs at the point the value
   * is actually CONSUMED - `/signup`'s own landing page - not at every place it is merely
   * passed along. Adding a second check here would be exactly the "do not write a second
   * path check" this app's redirect guard explicitly forbids.
   */
  next: string;
}

/**
 * The account-creation half of `InviteActions` - spec §11's case 3 ("a brand-new user
 * registers and accepts in one flow"), as far as authentication goes.
 *
 * ADR 0006 (Accepted, supersedes ADR 0005): Cycle 1 "does not ship /auth/oauth/google or
 * /auth/oauth/apple, the oauth_identities table, or any provider ID-token verifier" - OAuth
 * sign-in lands in the calendar cycle, not this one. This component used to render three
 * provider chips ('Apple', 'Google', 'Email'), each announcing "isn't available yet" when
 * clicked - honest about today, but misleading about the roadmap, since it offered two
 * providers this cycle has already decided never to build. Email is the only honest option,
 * so it is the only one left, rewritten as the one real action this flow has: create an
 * account and land back on this invite, signed in.
 */
export function SignupPrompt({ next }: SignupPromptProps) {
  const router = useRouter();

  return (
    <Button
      variant="secondary"
      onClick={() => router.push(`/signup?${new URLSearchParams({ next }).toString()}`)}
      data-testid="invite-signup"
    >
      Create an account and join
    </Button>
  );
}
