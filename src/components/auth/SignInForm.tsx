'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { LoginSchema, UserSchema } from '@tandem/shared';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { postJson } from '@/lib/auth/bffClient';
import { noticeFor } from '@/lib/auth/copy';
import { isLoginField, matchedFieldErrors } from '@/lib/auth/fields';
import { safeNextPath } from '@/lib/auth/redirect';
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
 * `/plans` when none was given - but ONLY after `safeNextPath` (src/lib/auth/redirect.ts)
 * has passed it. FIX ROUND 1, Important 1: this used to be `router.replace(next ... : '/plans')`
 * with no same-origin check, so `/signin?next=https://evil.example/tandem` sent a user who
 * had JUST AUTHENTICATED to an off-origin page of the attacker's choosing. `next` is now
 * rejected (falling back to `/plans`) unless it is a single-leading-slash, same-document path -
 * see that module's own docblock for the exact accept/reject rules and why.
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
 *
 * FIX ROUND 1, Important 2: a 422 whose `errors` map names no field this form renders (an
 * invented key like `captcha`, or a schema field this form simply does not collect) used to
 * fall into the generic-mapping loop below, match NOTHING, and hit the unconditional `return`
 * right after it - the submit silently did nothing and the API's own `message` was dropped.
 * `matchedFieldErrors` now reports how many entries actually matched `isLoginField`, and this
 * only returns early (skipping `setFormError`) when that count is greater than zero.
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
        router.replace(safeNextPath(next));
        return;
      }
      setFormError('Something went wrong on our end. Please try again.');
      return;
    }

    const { failure } = result;
    if (failure.fieldErrors !== null) {
      const matched = matchedFieldErrors(failure.fieldErrors, isLoginField);
      if (matched.length > 0) {
        for (const [field, message] of matched) {
          form.setError(field, { type: 'server', message });
        }
        return;
      }
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
