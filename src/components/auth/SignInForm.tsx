'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { LoginSchema, UserSchema } from '@tandem/shared';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { postJson } from '@/lib/auth/bffClient';
import { noticeFor } from '@/lib/auth/copy';
import { isLoginField } from '@/lib/auth/fields';
import { Button } from '@/components/ui/Button';
import { FormError } from '@/components/ui/FormError';
import { Input } from '@/components/ui/Input';
import styles from './authPage.module.css';

type FormInput = z.input<typeof LoginSchema>;
type FormOutput = z.output<typeof LoginSchema>;

/** `/auth/login`'s 200 body, from `loginHandler`: `{user}`, no token - §91. */
const LoginSuccessSchema = z.object({ user: UserSchema });

export interface SignInFormProps {
  next?: string | undefined;
  prefilledEmail?: string | undefined;
  reason?: string | undefined;
}

/**
 * Spec §11's sign-in surface. POSTs `/api/auth/login`, then `router.replace` to `next` (the
 * page that redirected here, e.g. `/plans?next=` round-tripped through the query string) or
 * `/plans` when none was given.
 *
 * **401** answers `{message}` with NO `errors` - the API cannot say which field was wrong
 * ("These credentials do not match our records." never distinguishes a bad email from a bad
 * password), and guessing would be a lie - so this always renders as a form-level `FormError`,
 * never against either field. **429** answers the same shape plus `Retry-After`, which
 * `src/lib/auth/bffClient.ts` already turns into "Try again in N minutes" appended to the
 * message before this component ever sees it - and deliberately NO remaining-attempts count:
 * §8.2's login throttle counts FAILED attempts and a success clears the budget, so any number
 * shown would be wrong the instant the next attempt succeeds. `X-RateLimit-Remaining` is not
 * even relayed for register/forgot-password, but it would be wrong to show here even if it
 * were relayed for login too.
 */
export function SignInForm({ next, prefilledEmail, reason }: SignInFormProps) {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const form = useForm<FormInput, unknown, FormOutput>({
    resolver: zodResolver(LoginSchema),
    defaultValues: { email: prefilledEmail ?? '', password: '' },
  });

  const onSubmit = form.handleSubmit(async (values) => {
    setFormError(null);
    setPending(true);
    const result = await postJson('/api/auth/login', {
      email: values.email,
      password: values.password,
    });
    setPending(false);

    if (result.kind === 'ok') {
      const parsed = LoginSuccessSchema.safeParse(result.json);
      if (parsed.success) {
        router.replace(next !== undefined && next.length > 0 ? next : '/plans');
        return;
      }
      setFormError('Something went wrong on our end. Please try again.');
      return;
    }

    const { failure } = result;
    if (failure.fieldErrors !== null) {
      for (const [field, message] of Object.entries(failure.fieldErrors)) {
        if (isLoginField(field)) {
          form.setError(field, { type: 'server', message });
        }
      }
      return;
    }
    setFormError(failure.message);
  });

  const notice = formError === null ? noticeFor(reason) : null;

  return (
    <form className={styles.card} onSubmit={onSubmit}>
      <h1 className={styles.heading}>Sign in</h1>

      {notice !== null ? (
        <p className={styles.notice} data-testid="sign-in-notice">
          {notice}
        </p>
      ) : null}

      <div className={styles.fields}>
        <Input
          label="Email"
          type="email"
          autoComplete="email"
          autoCapitalize="none"
          autoCorrect="off"
          error={form.formState.errors.email?.message}
          data-testid="sign-in-email"
          {...form.register('email')}
        />
        <Input
          label="Password"
          revealable
          autoComplete="current-password"
          error={form.formState.errors.password?.message}
          data-testid="sign-in-password"
          {...form.register('password')}
        />
      </div>

      {formError !== null ? <FormError message={formError} /> : null}

      <Button type="submit" variant="primary" pending={pending} data-testid="sign-in-submit">
        Sign in
      </Button>

      <div className={styles.links}>
        <a className={styles.link} href="/forgot-password" data-testid="sign-in-forgot-password">
          Forgot password?
        </a>
        <a className={styles.link} href="/signup" data-testid="sign-in-signup-link">
          Create an account
        </a>
      </div>
    </form>
  );
}
