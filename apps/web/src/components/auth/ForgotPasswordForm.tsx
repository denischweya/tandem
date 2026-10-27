'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { ForgotPasswordSchema } from '@tandem/shared';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { postJson } from '@/lib/auth/bffClient';
import { isForgotPasswordField, matchedFieldErrors } from '@/lib/auth/fields';
import { Button } from '@/components/ui/Button';
import { FormError } from '@/components/ui/FormError';
import { Input } from '@/components/ui/Input';
import styles from './authPage.module.css';

type FormInput = z.input<typeof ForgotPasswordSchema>;
type FormOutput = z.output<typeof ForgotPasswordSchema>;

/**
 * Spec §11's forgot-password surface. `POST /auth/forgot-password` answers 202 with an EMPTY
 * body - `forgotPasswordHandler` never parses one - identically for a known address, an
 * unknown one and a suspended account. So the success state below is ONE FIXED SENTENCE that
 * never implies whether an account exists: there is no field in the response to brand a
 * "known address" path versus an "unknown address" path, and inventing one from anything else
 * (timing, a different success message, a disabled resend button) would be the leak this
 * endpoint's whole design avoids. See Step 6.4 in the task report for the demonstration that
 * there is nothing to branch on.
 */
export function ForgotPasswordForm() {
  const [formError, setFormError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  // The address a request was sent FOR, kept so "send another link" can resend without
  // asking the visitor to retype it - NOT the same thing as "it succeeded"; see `sentTo`'s
  // one reader below.
  const [sentTo, setSentTo] = useState<string | null>(null);

  const form = useForm<FormInput, unknown, FormOutput>({
    resolver: zodResolver(ForgotPasswordSchema),
    defaultValues: { email: '' },
  });

  async function submit(email: string) {
    setFormError(null);
    setPending(true);
    const result = await postJson('/api/auth/forgot-password', { email });
    setPending(false);

    if (result.kind === 'ok') {
      setSentTo(email);
      return;
    }

    const { failure } = result;
    if (failure.fieldErrors !== null) {
      // FIX ROUND 1, Important 2: only return early when at least one entry actually
      // matched - see `matchedFieldErrors`'s own docblock in src/lib/auth/fields.ts.
      const matched = matchedFieldErrors(failure.fieldErrors, isForgotPasswordField);
      if (matched.length > 0) {
        for (const [field, message] of matched) {
          form.setError(field, { type: 'server', message });
        }
        return;
      }
    }
    setFormError(failure.message);
  }

  const onSubmit = form.handleSubmit((values) => submit(values.email));

  if (sentTo !== null && formError === null) {
    return (
      <div className={styles.card}>
        <h1 className={styles.heading}>Check your inbox</h1>
        <p className={styles.subtitle} data-testid="forgot-password-confirmation">
          If that address has an account, we&apos;ve sent a link. Check your inbox.
        </p>
        <p className={styles.subtitle}>
          Requesting another link will invalidate the one we just sent.
        </p>
        <Button
          type="button"
          variant="secondary"
          pending={pending}
          onClick={() => void submit(sentTo)}
          data-testid="forgot-password-resend"
        >
          Send another link
        </Button>
      </div>
    );
  }

  return (
    <form className={styles.card} onSubmit={onSubmit}>
      <h1 className={styles.heading}>Forgot password</h1>
      <p className={styles.subtitle}>
        Enter the address on your account and we&apos;ll send a link to reset your password.
      </p>

      <Input
        label="Email"
        type="email"
        autoComplete="email"
        autoCapitalize="none"
        autoCorrect="off"
        error={form.formState.errors.email?.message}
        data-testid="forgot-password-email"
        {...form.register('email')}
      />

      {formError !== null ? <FormError message={formError} /> : null}

      <Button
        type="submit"
        variant="primary"
        pending={pending}
        data-testid="forgot-password-submit"
      >
        Send reset link
      </Button>

      <div className={styles.links}>
        <a className={styles.link} href="/signin" data-testid="forgot-password-signin-link">
          Back to sign in
        </a>
      </div>
    </form>
  );
}
