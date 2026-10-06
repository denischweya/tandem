/**
 * Compile-time proof that a hand-written Zod schema matches the generated API type.
 *
 * Zod schemas are hand-written because generating usable validators from OpenAPI
 * does not work well. That makes them the one place where drift from the API
 * contract could hide. These helpers close that gap: if a field is renamed in
 * Laravel, regenerating types makes the corresponding assertion fail to compile.
 *
 * Usage, once endpoints exist (Plan 4):
 *
 *   import type { components } from '../types/api';
 *   import type { Expect, Assignable } from './assignability';
 *   import { CreatePlanSchema } from './plans';
 *   import type { z } from 'zod';
 *
 *   export type _CreatePlanMatchesApi = Expect<
 *     Assignable<z.infer<typeof CreatePlanSchema>, components['schemas']['CreatePlanRequest']>
 *   >;
 */

/** Fails to compile unless T is exactly `true`. */
export type Expect<T extends true> = T;

/** `true` when A is assignable to B, `false` otherwise. */
export type Assignable<A, B> = [A] extends [B] ? true : false;

/** `true` only when A and B are mutually assignable. Stricter than Assignable. */
export type Equivalent<A, B> =
  Assignable<A, B> extends true ? (Assignable<B, A> extends true ? true : false) : false;
