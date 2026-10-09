import { z } from 'zod';

import type { paths } from '../types/api';

import type { Expect, WireEquivalent } from './assignability';

/**
 * PATCH /me's request body. This is the one accessor in the repo where `NonNullable` is
 * load-bearing rather than uniform: the document gives this endpoint `requestBody?`, because
 * every field is optional and so an empty request is valid.
 */
type UpdateProfileBody = NonNullable<
  paths['/me']['patch']['requestBody']
>['content']['application/json'];

/**
 * Mirrors UpdateProfileRequest::rules(). All three fields are ['sometimes', 'required', …] -
 * a PATCH, so omitting a field leaves it unchanged, but sending it empty is a 422. That is
 * why each is `.min(1)` inside `.optional()`, and why `locale` here has a `.min(1)` that
 * RegisterSchema's does not: the upstream rules genuinely differ, and this file mirrors the
 * difference rather than smoothing it over.
 *
 * `.trim()` on display_name mirrors prepareForValidation(), as in RegisterSchema.
 *
 * `email` and `email_verified` are absent deliberately, not forgotten - upstream records
 * that changing an address needs a re-verification flow no cycle has specified, and
 * verification state is never client-supplied. Adding either here would fail
 * `_UpdateProfileMatchesApi`, which is the intended outcome.
 */
export const UpdateProfileSchema = z.object({
  display_name: z.string().trim().min(1).max(80).optional(),
  timezone: z.string().min(1).optional(),
  locale: z.string().min(1).max(10).optional(),
});

export type _UpdateProfileMatchesApi = Expect<
  WireEquivalent<z.infer<typeof UpdateProfileSchema>, UpdateProfileBody>
>;
