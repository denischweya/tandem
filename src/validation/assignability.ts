/**
 * Compile-time proof that a hand-written Zod schema matches the generated API type.
 *
 * Zod schemas are hand-written because generating usable validators from OpenAPI
 * does not work well. That makes them the one place where drift from the API
 * contract could hide. These helpers close that gap: if a field is renamed in
 * Laravel, regenerating types makes the corresponding assertion fail to compile.
 *
 * Usage:
 *
 *   import type { components } from '../types/api';
 *   import type { Expect, WireEquivalent } from './assignability';
 *   import { RegisterSchema } from './auth';
 *   import type { z } from 'zod';
 *
 *   export type _RegisterMatchesApi = Expect<
 *     WireEquivalent<z.infer<typeof RegisterSchema>, components['schemas']['RegisterRequest']>
 *   >;
 *
 * `WireEquivalent` is the helper to reach for when proving a schema against the contract.
 * `Assignable` and `Equivalent` are the primitives it is built from, exported for narrower
 * one-off checks; `WireShape`'s docblock records why neither is usable directly on a
 * Zod-inferred object in this repo.
 *
 * proof.ts is the regression test for everything in this file, and is the only place in the
 * repo where `@ts-expect-error` is allowed: a negative type-level assertion has no other way
 * to be checkable.
 */

/** Fails to compile unless T is exactly `true`. */
export type Expect<T extends true> = T;

/** `true` when A is assignable to B, `false` otherwise. */
export type Assignable<A, B> = [A] extends [B] ? true : false;

/** `true` only when A and B are mutually assignable. Stricter than Assignable. */
export type Equivalent<A, B> =
  Assignable<A, B> extends true ? (Assignable<B, A> extends true ? true : false) : false;

/**
 * Types with no properties to normalize. `Date` is in the list because `Date extends object`
 * is true, and a mapped type over its keys would produce an object that is no longer a Date.
 */
type WireLeaf = string | number | boolean | bigint | symbol | null | undefined | Date;

/** `true` when K is an optional property of T, or is typed to permit `undefined`. */
type IsOptionalProp<T, K extends keyof T> = undefined extends T[K] ? true : false;

/**
 * T rewritten into a canonical form in which two types compare equal under TypeScript's
 * structural rules exactly when they describe the same JSON wire format. Intended for
 * JSON-shaped types.
 *
 * Two things are canonicalized, both of them measured rather than anticipated.
 *
 * 1. `undefined` is dropped from an optional property's value type. openapi-typescript 7.13
 *    emits an optional property as `locale?: string`, while Zod 4's `.optional()` infers
 *    `locale?: string | undefined`. This repo compiles with `exactOptionalPropertyTypes`
 *    (spec §14.1), under which those are NOT mutually assignable - the first permits a key
 *    that is present and explicitly undefined, the second does not. Without this step a
 *    *correct* schema raises TS2344 against a *correct* contract, for a reason that is a
 *    difference in representation rather than drift, which would train the next person to
 *    delete assertions. The step is sound rather than merely convenient because `undefined`
 *    is not a JSON value: JSON.stringify omits a property whose value is undefined, so the
 *    two representations describe the same bytes. `null` is a JSON value and is NOT
 *    stripped - a nullable field is a different contract from a non-nullable one.
 *
 * 2. Every property is then made required with `-?`, and the optionality it carried moves
 *    into the value type as `| undefined`. This is not cosmetic. Two object types that
 *    differ only in the NAME of an optional property are mutually assignable:
 *    `{ id: string; locale?: string }` and `{ id: string; locale_code?: string }` are each
 *    assignable to the other, because neither optional key is required to be present and
 *    excess-property checking does not apply outside fresh object literals. Measured, not
 *    assumed - an earlier version of this helper stopped at step 1 and let a renamed
 *    `locale` through. Making every key required forces an exact key-set comparison, while
 *    moving optionality into the value type keeps it checkable: `string | undefined` and
 *    `string` are not mutually assignable, so an optional field made required still fails,
 *    and so does a required field made optional.
 *
 * What survives, therefore, is exactly the contract: the set of property names, whether each
 * one may be omitted, and each one's value type - recursively, so a rename nested inside
 * `SessionResource.user` fails too.
 *
 * One conflation is accepted and recorded: a REQUIRED property typed `T | undefined` is
 * indistinguishable here from an optional `T`, because `undefined extends T[K]` is the only
 * way to read optionality off an indexed access. On the wire the two agree - both may
 * serialize to an absent key - and openapi-typescript never emits the former anyway. If a
 * future generator does, this is the line to revisit.
 *
 * `Required<T>` plus a blanket undefined strip is the shortcut to avoid: it makes
 * `{ locale?: string }` and `{ locale: string }` compare equal and silently discards the
 * required-versus-optional half of the contract, which is the difference between a client
 * that may omit a field and one that must send it. proof.ts asserts, in both directions,
 * that this helper refuses that conflation.
 */
export type WireShape<T> = T extends WireLeaf
  ? T
  : T extends object
    ? {
        [K in keyof T]-?: IsOptionalProp<T, K> extends true
          ? WireShape<Exclude<T[K], undefined>> | undefined
          : WireShape<T[K]>;
      }
    : T;

/**
 * `true` when A and B describe the same JSON wire format: the same property names, the same
 * optionality per property, and the same value types - ignoring only the `undefined` that
 * `exactOptionalPropertyTypes` makes visible in an optional property's type.
 *
 * Built on Equivalent (mutual assignability) rather than Assignable, and that choice is
 * load-bearing rather than defensive. One-way assignability is TRUE when a schema makes an
 * optional contract field required, because `{ a: string }` IS assignable to
 * `{ a: string | undefined }`. The one-way form therefore cannot fail when a schema turns
 * `locale?` into `locale` - drift in the direction that makes a client send a field the
 * server never asked for. proof.ts asserts that one-way gap exists, so this paragraph cannot
 * quietly stop being true.
 */
export type WireEquivalent<A, B> = Equivalent<WireShape<A>, WireShape<B>>;
