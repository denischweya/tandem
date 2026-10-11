'use client';

import { UserSchema } from '@tandem/shared';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { z } from 'zod';

import { submitBff } from '@/lib/auth/bffClient';
import { Button } from '@/components/ui/Button';
import { ErrorState } from '@/components/ui/ErrorState';
import { LoadingState } from '@/components/ui/LoadingState';
import styles from './authPage.module.css';

const VerifyEmailSuccessSchema = z.object({ user: UserSchema });

type Phase = 'idle' | 'pending' | 'success' | 'error';

/**
 * The landing page for `EmailVerificationLink::for()`'s URL - `config/app.php` points
 * `web_url` at this app, so `tandem-web` is the ONLY place this link can land in Cycle 1.
 *
 * TWO requirements this file exists to satisfy together, and getting either one wrong breaks
 * the other:
 *
 * 1. THE RAW QUERY SUBSTRING MUST BE RELAYED VERBATIM. `window.location.search.slice(1)`,
 *    never `new URLSearchParams(window.location.search).toString()`. `UrlGenerator::
 *    hasCorrectSignature` rebuilds the signed original from the raw query string in arrival
 *    order on the server; a `URLSearchParams` round trip changes bytes (measured server-side,
 *    `src/server/handlers.ts`'s own docblock: `%20` becomes `+`, `~` becomes `%7E`, `%2A`
 *    becomes `*`), and ANY such change is a permanent 403 - the link can never be retried
 *    once mangled. This is the browser half of that requirement; `verifyEmailHandler`'s own
 *    `request.url.slice(questionIndex)` is the server half, and Step 6.3 of the task report
 *    is the measured proof that both halves must independently hold.
 * 2. A BUTTON, NEVER AN AUTOMATIC POST ON MOUNT. `POST /auth/verify-email` is a POST rather
 *    than a GET precisely because mail clients, link scanners and corporate security gateways
 *    prefetch GET URLs - firing this on mount would re-create that exact hazard one layer up,
 *    turning THIS page's own load (which a scanner can also trigger) into the thing that
 *    consumes a single-use verification link before the real recipient ever clicks it. A
 *    Server Component cannot do either job: `searchParams` is a parsed object, and
 *    `window.location` does not exist during server rendering.
 */
export function VerifyEmailClient() {
  const router = useRouter();
  const [phase, setPhase] = useState<Phase>('idle');
  const [message, setMessage] = useState<string | null>(null);

  async function handleVerify() {
    setPhase('pending');
    const rawQuery = window.location.search.slice(1);
    const result = await submitBff(`/api/auth/verify-email?${rawQuery}`, { method: 'POST' });

    if (result.kind === 'ok') {
      const parsed = VerifyEmailSuccessSchema.safeParse(result.json);
      if (parsed.success) {
        setPhase('success');
        return;
      }
      setMessage('Something went wrong on our end. Please try again.');
      setPhase('error');
      return;
    }

    // A 403 covers all four causes `verifyEmailHandler`'s own docblock names (a tampered
    // signature, an expired link, an unknown id, a hash that no longer matches the current
    // address) with ONE message - and there is no resend endpoint, so EVERY failure here,
    // whatever its status, gets the same action: sign in, where a verified session can be
    // re-checked, rather than a "Request a new link" button with nothing behind it.
    setMessage(result.failure.message);
    setPhase('error');
  }

  if (phase === 'pending') {
    return <LoadingState label="Verifying your email…" />;
  }

  if (phase === 'error') {
    return (
      <ErrorState
        title="This link isn't working"
        body={message ?? 'Something went wrong on our end. Please try again.'}
        action={{ label: "Sign in and we'll help", onClick: () => router.push('/signin') }}
      />
    );
  }

  if (phase === 'success') {
    return (
      <div className={styles.card}>
        <h1 className={styles.heading}>Your email is verified</h1>
        <p className={styles.subtitle}>You&apos;re all set.</p>
        <Button
          type="button"
          variant="primary"
          onClick={() => router.push('/plans')}
          data-testid="verify-email-continue"
        >
          Continue to Tandem
        </Button>
      </div>
    );
  }

  return (
    <div className={styles.card}>
      <h1 className={styles.heading}>Verify your email</h1>
      <p className={styles.subtitle}>
        Confirm this email address to finish setting up your account.
      </p>
      <Button
        type="button"
        variant="primary"
        onClick={() => void handleVerify()}
        data-testid="verify-email-submit"
      >
        Verify email
      </Button>
    </div>
  );
}
