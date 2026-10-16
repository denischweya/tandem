'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { RegisterSchema, UserSchema } from '@tandem/shared';
import { useRouter } from 'next/navigation';
import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { postJson } from '@/lib/auth/bffClient';
import { isRegisterField, matchedFieldErrors } from '@/lib/auth/fields';
import { safeNextPath } from '@/lib/auth/redirect';
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
 * MEASURED BUG (now fixed twice over), found by this file's own test ("routes to /signin with
 * the address prefilled when signedIn is false"): `z.union` tries members IN ORDER and a
 * plain `z.object()` is non-strict by default, so with the narrower `{user, signedIn, email}`
 * schema listed SECOND, a response that actually carries `signedIn: false` still matched the
 * FIRST, broader `{user}` schema first - it just silently stripped `signedIn`/`email` as
 * unrecognised keys instead of failing - and `'signedIn' in parsed.data` then read `false`,
 * routing a registered-but-not-signed-in user to `/plans` instead of `/signin`.
 *
 * FIX ROUND 1, Important 4: this docblock used to claim "the fix is ORDER, not a `.strict()`
 * call on either member" - WRONG ON THE MERITS, measured by the reviewer: reordering only
 * guards against the two members being swapped. It does nothing for a DIFFERENT drift the
 * reviewer reproduced directly - a 201 body of `{user, signedIn: false}` WITHOUT `email` -
 * which, with the narrow member listed first (the post-self-correction order above), fails
 * the narrow member (missing `email`) and STILL matched the broad `{user}` member, which
 * silently accepted the extra `signedIn` key and stripped it, same as the original bug.
 * Probe output before this fix: `push= [] replace= [["/plans"]]` - a registered-but-not-
 * signed-in user routed straight to an authenticated surface.
 *
 * `.strict()` on the broad member is the actual fix, and it makes member ORDER irrelevant:
 * a strict `{user}` schema rejects ANY extra key, so a body carrying `signedIn` (true, false,
 * or anything else) can never match it regardless of where it sits in the union - either the
 * narrow member matches first, or the body fails BOTH members and `parsed.success` is false,
 * which routes into the existing "Something went wrong" branch below. A body that is
 * genuinely just `{user}` still matches the broad member either way. Swapping the two members
 * no longer changes behaviour for any input - verified by running this file's existing order
 * test with the members swapped AND with them in the order below; both pass.
 */
const RegisterResponseSchema = z.union([
  z.object({ user: UserSchema, signedIn: z.literal(false), email: z.string() }),
  z.object({ user: UserSchema }).strict(),
]);

export interface SignUpFormProps {
  next?: string | undefined;
}

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
 *
 * TASK 12: `next` (e.g. `/invite/{token}`, from `InviteActions`'s "Create an account and
 * join") is this form's half of spec §11's case 3 - "a brand-new user registers and accepts
 * in one flow." On the SIGNED-IN success path this now replaces to `safeNextPath(next)`
 * instead of a hardcoded `/plans`, mirroring `SignInForm` exactly (same function, same
 * fallback, no second check). On the registered-but-NOT-signed-in path, `next` is forwarded
 * - raw, unvalidated - onto the `/signin` hand-off: carrying a value in a query string is not
 * consuming it, and the actual consumption point is `SignInForm`'s own `safeNextPath` call
 * once the user lands there, exactly as `/plans`'s existing `redirect('/signin?next=%2Fplans')`
 * already forwards a value it does not itself validate.
 */
export function SignUpForm({ next }: SignUpFormProps) {
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
        if (next !== undefined) params.set('next', next);
        router.push(`/signin?${params.toString()}`);
        return;
      }
      router.replace(safeNextPath(next));
      return;
    }

    const { failure } = result;
    if (failure.fieldErrors !== null) {
      // CORRECTED (Wave 1b final review, W3/D4/3.9). This used to argue that `isRegisterField`
      // should cover `timezone` too - "this form registers an Input for every field, so no
      // special case" - citing "a special case is exactly the 'same decision made in one
      // place and not its sibling' shape this wave has hit repeatedly" as the reason NOT to
      // exclude it. `isRegisterField` (fields.ts) now DOES exclude `timezone`, deliberately,
      // for the opposite reason: the Input it renders is read-only, the visitor cannot
      // correct it, and web has no Profile page this cycle to correct it from either - a 422
      // keyed `timezone` alone now falls through to the form-level `FormError` below instead
      // of attaching to a field nothing can act on while simultaneously suppressing that
      // message. See `fields.ts`'s own corrected docblock on `REGISTER_FIELDS`.
      //
      // FIX ROUND 1, Important 2: only return early when at least one entry actually matched
      // - see `matchedFieldErrors`'s own docblock. An `errors` map naming no field this form
      // renders used to hit the unconditional `return` below and show nothing at all.
      const matched = matchedFieldErrors(failure.fieldErrors, isRegisterField);
      if (matched.length > 0) {
        for (const [field, message] of matched) {
          form.setError(field, { type: 'server', message });
        }
        return;
      }
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
        <a
          className={styles.link}
          href={
            next === undefined ? '/signin' : `/signin?${new URLSearchParams({ next }).toString()}`
          }
          data-testid="create-account-signin-link"
        >
          Already have an account? Sign in
        </a>
      </div>
    </form>
  );
}
