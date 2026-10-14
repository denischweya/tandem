'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { ResetPasswordSchema } from '@tandem/shared';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { postJson } from '@/lib/auth/bffClient';
import { isResetField, matchedFieldErrors } from '@/lib/auth/fields';
import { Button } from '@/components/ui/Button';
import { ErrorState } from '@/components/ui/ErrorState';
import { FormError } from '@/components/ui/FormError';
import { Input } from '@/components/ui/Input';
import styles from './authPage.module.css';

type FormInput = z.input<typeof ResetPasswordSchema>;
type FormOutput = z.output<typeof ResetPasswordSchema>;

export interface ResetPasswordFormProps {
  token: string;
  email: string;
}

/**
 * Spec §11's reset-password surface, reached from `AppServiceProvider`'s reset URL
 * (`?token=…&email=…`). Unlike `/verify-email`, `searchParams` IS the right way to read
 * these two: nothing here is signed, and both are ordinary form inputs the visitor could, in
 * principle, see and edit - which is exactly why `email` is rendered as a normal editable
 * `Input` while `token` is a hidden field instead: a 64-character hex string is not something
 * anyone needs to read or retype, and showing it would invite exactly the kind of accidental
 * edit (a trailing space, a lost character) that turns a working link into a 422.
 *
 * On 204 this clears nothing client-side and attempts no refresh - `resetPasswordHandler`
 * already cleared both cookies server-side, because the reset revoked every token the user
 * held - and routes to `/signin` with the confirmation sentence (`noticeFor`'s
 * `reason=passwordReset`).
 *
 * The ONE special case: a dead reset token is a 422 whose `errors.token` carries the SAME
 * sentence as `message`. Rendering that against the hidden token input (the field it would
 * naturally map onto) would be invisible - Step 6.2 of the task report demonstrates exactly
 * that by removing this carve-out - so it is pulled out before the generic field-mapping loop
 * runs and replaces the whole form with an `ErrorState` plus a real next step.
 */
export function ResetPasswordForm({ token, email }: ResetPasswordFormProps) {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);
  const [tokenError, setTokenError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [confirmPassword, setConfirmPassword] = useState('');
  const [confirmError, setConfirmError] = useState<string | null>(null);

  const form = useForm<FormInput, unknown, FormOutput>({
    resolver: zodResolver(ResetPasswordSchema),
    defaultValues: { token, email, password: '' },
  });

  const onSubmit = form.handleSubmit(async (values) => {
    if (values.password !== confirmPassword) {
      setConfirmError('Passwords do not match.');
      return;
    }
    setConfirmError(null);
    setFormError(null);
    setTokenError(null);
    setPending(true);
    const result = await postJson('/api/auth/reset-password', values);
    setPending(false);

    if (result.kind === 'ok') {
      // The reset revoked every token this account held, on every device - never try to
      // refresh. Sign In carries the exact confirmation sentence via `noticeFor`.
      const params = new URLSearchParams({ email: values.email, reason: 'passwordReset' });
      router.replace(`/signin?${params.toString()}`);
      return;
    }

    const { failure } = result;
    if (failure.fieldErrors !== null) {
      // `token` is pulled out FIRST, before the generic loop, so it can never fall through to
      // `isResetField`'s guard (which deliberately excludes it - see src/lib/auth/fields.ts).
      const { token: deadTokenMessage, ...rest } = failure.fieldErrors;
      if (deadTokenMessage !== undefined) {
        setTokenError(deadTokenMessage);
        return;
      }
      // FIX ROUND 1, Important 2: only return early when at least one entry actually
      // matched - see `matchedFieldErrors`'s own docblock in src/lib/auth/fields.ts. Applies
      // to `rest` (the token carve-out above already handled, and always returns).
      const matched = matchedFieldErrors(rest, isResetField);
      if (matched.length > 0) {
        for (const [field, message] of matched) {
          form.setError(field, { type: 'server', message });
        }
        return;
      }
    }
    setFormError(failure.message);
  });

  if (tokenError !== null) {
    return (
      <div className={styles.card}>
        <ErrorState
          title="This link isn't usable"
          body={tokenError}
          action={{ label: 'Request a new link', onClick: () => router.push('/forgot-password') }}
        />
      </div>
    );
  }

  return (
    <form className={styles.card} onSubmit={onSubmit}>
      <h1 className={styles.heading}>Reset your password</h1>
      <p className={styles.subtitle}>Choose a new password for your account.</p>

      <input type="hidden" data-testid="reset-password-token" {...form.register('token')} />

      <div className={styles.fields}>
        <Input
          label="Email"
          type="email"
          autoComplete="email"
          autoCapitalize="none"
          autoCorrect="off"
          error={form.formState.errors.email?.message}
          data-testid="reset-password-email"
          {...form.register('email')}
        />
        <Input
          label="New password"
          revealable
          helpText="At least 12 characters."
          autoComplete="new-password"
          error={form.formState.errors.password?.message}
          data-testid="reset-password-new-password"
          {...form.register('password')}
        />
        <Input
          label="Confirm new password"
          revealable
          autoComplete="new-password"
          value={confirmPassword}
          onChange={(event) => {
            setConfirmPassword(event.target.value);
            setConfirmError(null);
          }}
          error={confirmError ?? undefined}
          data-testid="reset-password-confirm-password"
        />
      </div>

      {formError !== null ? <FormError message={formError} /> : null}

      <Button type="submit" variant="primary" pending={pending} data-testid="reset-password-submit">
        Reset password
      </Button>
    </form>
  );
}
