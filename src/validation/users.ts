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
 * why each is `.min(1)` inside `.optional()`.
 *
 * `.trim()` goes ahead of every length check here, for the reason auth.ts's MIDDLEWARE note
 * records: TrimStrings transforms each field before `required` sees it, so '   ' is a 422
 * upstream, while Zod's `.min(1)` counts characters and accepted it. On display_name the trim
 * also mirrors prepareForValidation(), which trims that one field a second time.
 *
 * This docblock used to record an asymmetry with RegisterSchema.locale, which carried no
 * `.min(1)` because its upstream rule carries no `required`. There is none left to record:
 * ConvertEmptyStringsToNull reaches `locale: ""` before either rules() array does, so it was
 * always a 422 at both endpoints - see RegisterSchema's locale paragraph. The two schemas now
 * agree because the two endpoints always did.
 *
 * `email` and `email_verified` are absent deliberately, not forgotten - upstream records
 * that changing an address needs a re-verification flow no cycle has specified, and
 * verification state is never client-supplied. Adding either here would fail
 * `_UpdateProfileMatchesApi`, which is the intended outcome.
 */
export const UpdateProfileSchema = z.object({
  display_name: z.string().trim().min(1).max(80).optional(),
  timezone: z.string().trim().min(1).optional(),
  locale: z.string().trim().min(1).max(10).optional(),
});

export type _UpdateProfileMatchesApi = Expect<
  WireEquivalent<z.infer<typeof UpdateProfileSchema>, UpdateProfileBody>
>;
