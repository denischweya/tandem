'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { RegisterSchema, UserSchema } from '@tandem/shared';
import { useRouter } from 'next/navigation';
import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { postJson } from '@/lib/auth/bffClient';
import { isRegisterField } from '@/lib/auth/fields';
import { resolveTimezone } from '@/lib/auth/timezone';
import { Button } from '@/components/ui/Button';
import { FormError } from '@/components/ui/FormError';
import { Input } from '@/components/ui/Input';
import styles from './authPage.module.css';

type FormInput = z.input<typeof RegisterSchema>;
type FormOutput = z.output<typeof RegisterSchema>;

/**
 * `/auth/register`'s 201 body, from `registerHandler`. Two shapes under the SAME status,
 * discriminated on the PRESENCE of `signedIn` rather than its value: the signed-in branch
 * returns `{user}` with no `signedIn` key at all (`registerHandler`: `jsonResponse(201, {user:
 * session.user})`), and only the registered-but-not-signed-in branch sets `signedIn: false`
 * (`jsonResponse(201, {user, signedIn: false, email})`). There is no `signedIn: true` on the
 * wire to check for - `'signedIn' in body` is the correct discriminant, not `body.signedIn`.
 *
 * MEASURED BUG, found by this file's own test ("routes to /signin with the address prefilled
 * when signedIn is false"): `z.union` tries members IN ORDER and a plain `z.object()` is
 * non-strict by default, so with the narrower `{user, signedIn, email}` schema listed SECOND,
 * a response that actually carries `signedIn: false` still matched the FIRST, broader
 * `{user}` schema first - it just silently stripped `signedIn`/`email` as unrecognised keys
 * instead of failing - and `'signedIn' in parsed.data` then read `false`, routing a
 * registered-but-not-signed-in user to `/plans` instead of `/signin`. The fix is ORDER, not a
 * `.strict()` call on either member: the MORE SPECIFIC shape goes first, so it only yields to
 * the broader one when its own extra required fields are genuinely absent.
 */
const RegisterResponseSchema = z.union([
  z.object({ user: UserSchema, signedIn: z.literal(false), email: z.string() }),
  z.object({ user: UserSchema }),
]);

/**
 * Spec §11's create-account surface. `POST /auth/register` answers 201 with a user and NO
 * tokens (§91) - "create an account and be signed in" is two API calls, and `registerHandler`
 * (not this component) owns that sequencing; this form only has to react to which of the two
 * outcomes came back.
 *
 * On the registered-but-not-signed-in outcome this routes to `/signin` with the address
 * prefilled and an explanation, rather than retrying register (which would now 422 on
 * `unique`) or silently failing: register-then-login is two API calls and the second one can
 * fail on its own (login's throttle, a dropped connection) for a registration that still
 * succeeded.
 */
export function SignUpForm() {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const timezone = useMemo(() => resolveTimezone(), []);

  const form = useForm<FormInput, unknown, FormOutput>({
    resolver: zodResolver(RegisterSchema),
    defaultValues: { email: '', password: '', display_name: '', timezone },
  });

  const onSubmit = form.handleSubmit(async (values) => {
    setFormError(null);
    setPending(true);
    const result = await postJson('/api/auth/register', {
      email: values.email,
      password: values.password,
      display_name: values.display_name,
      timezone: values.timezone,
    });
    setPending(false);

    if (result.kind === 'ok') {
      const parsed = RegisterResponseSchema.safeParse(result.json);
      if (!parsed.success) {
        setFormError('Something went wrong on our end. Please try again.');
        return;
      }
      if ('signedIn' in parsed.data) {
        const params = new URLSearchParams({ email: parsed.data.email, reason: 'registered' });
        router.push(`/signin?${params.toString()}`);
        return;
      }
      router.replace('/plans');
      return;
    }

    const { failure } = result;
    if (failure.fieldErrors !== null) {
      // `isRegisterField` is the SAME derived-from-schema guard for every one of
      // `RegisterSchema`'s fields, `timezone` included - this form registers an Input for
      // every one of them (timezone's is read-only, but still `register()`-wired below, so
      // `fieldState`/`setError` reach it exactly like any editable field). No special case:
      // a special case is exactly the "same decision made in one place and not its sibling"
      // shape this wave has hit repeatedly.
      for (const [field, message] of Object.entries(failure.fieldErrors)) {
        if (isRegisterField(field)) {
          form.setError(field, { type: 'server', message });
        }
      }
      return;
    }
    setFormError(failure.message);
  });

  return (
    <form className={styles.card} onSubmit={onSubmit}>
      <h1 className={styles.heading}>Create an account</h1>

      <div className={styles.fields}>
        <Input
          label="Name"
          autoComplete="name"
          error={form.formState.errors.display_name?.message}
          data-testid="create-account-name"
          {...form.register('display_name')}
        />
        <Input
          label="Email"
          type="email"
          autoComplete="email"
          autoCapitalize="none"
          autoCorrect="off"
          error={form.formState.errors.email?.message}
          data-testid="create-account-email"
          {...form.register('email')}
        />
        <Input
          label="Password"
          revealable
          helpText="At least 12 characters."
          autoComplete="new-password"
          error={form.formState.errors.password?.message}
          data-testid="create-account-password"
          {...form.register('password')}
        />
        <Input
          label="Timezone"
          readOnly
          helpText="Detected automatically. You can change it later."
          error={form.formState.errors.timezone?.message}
          data-testid="create-account-timezone"
          {...form.register('timezone')}
        />
      </div>

      {formError !== null ? <FormError message={formError} /> : null}

      <Button type="submit" variant="primary" pending={pending} data-testid="create-account-submit">
        Create an account
      </Button>

      <div className={styles.links}>
        <a className={styles.link} href="/signin" data-testid="create-account-signin-link">
          Already have an account? Sign in
        </a>
      </div>
    </form>
  );
}
