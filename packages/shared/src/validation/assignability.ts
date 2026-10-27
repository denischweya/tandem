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
 *   import type { paths } from '../types/api';
 *   import type { Expect, WireEquivalent } from './assignability';
 *   import { RegisterSchema } from './auth';
 *   import type { z } from 'zod';
 *
 *   type RegisterBody = NonNullable<
 *     paths['/auth/register']['post']['requestBody']
 *   >['content']['application/json'];
 *
 *   export type _RegisterMatchesApi = Expect<
 *     WireEquivalent<z.infer<typeof RegisterSchema>, RegisterBody>
 *   >;
 *
 * Reach the contract through `paths`, as above, and not through
 * `components['schemas']['RegisterRequest']`. Both resolve to the same type here, but the
 * path form additionally pins the URL, the HTTP method and the media type, so moving an
 * endpoint breaks the proof rather than passing it. auth.ts and session.ts record the
 * accessor for every endpoint in this wave.
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

/**
 * `true` only when T is `any`.
 *
 * `0 extends 1 & T` is the standard probe, and it works for a reason worth recording: `1 & T`
 * collapses to `any` when T is `any`, and `0` IS assignable to `any`. For every other T the
 * intersection stays a subtype of `1`, which `0` is not assignable to - `unknown` gives
 * `1 & unknown` = `1`, `never` gives `never`. proof.ts pins both of those negative answers,
 * because a guard that also fired on `unknown` would make every proof in the repo fail closed.
 */
type IsAny<T> = 0 extends 1 & T ? true : false;

/**
 * `true` when A is assignable to B, `false` otherwise - with one deliberate exception: `any`
 * on either side answers `false`, though TypeScript's own assignability rule says `true`.
 *
 * `any` is assignable to everything and everything is assignable to `any`, so without the
 * guard `[A] extends [B]` answers true in BOTH directions the moment either side is `any`:
 * `Equivalent<any, X>` and `Equivalent<X, any>` are both `true` and every proof standing on
 * them passes while proving nothing. That is vacuous rather than wrong, which is the harder
 * failure to notice, because it reports as a green gate. A proof against `any` is not a
 * proof, so this answers false and the assertion fails instead - the outcome that gets read.
 *
 * Latent rather than live: there is no `any` in src/types/api.ts today. This guards the
 * version of that file that acquires one. The whole-type case is caught here; the `any` that
 * appears as one PROPERTY is invisible at this level and is caught in WireShape instead.
 */
export type Assignable<A, B> =
  IsAny<A> extends true ? false : IsAny<B> extends true ? false : [A] extends [B] ? true : false;

/**
 * `true` only when A and B are mutually assignable, in Assignable's sense - so `any` on
 * either side is `false` here too, rather than the `true` TypeScript would give. Stricter
 * than Assignable.
 */
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
 * The canonical form of `any`: branded with a `unique symbol`, so it is mutually assignable
 * with itself and with nothing else.
 *
 * It exists for the `any` that appears as one PROPERTY rather than as a whole type, which
 * Assignable's IsAny guard cannot see - neither side IS `any`, yet `{ locale: any }` and
 * `{ locale: string }` are mutually assignable, so a single `any` field makes a whole proof
 * vacuous. Measured: `WireShape<any>` was itself `any` before this branch existed, while
 * `WireShape<{ a: any }>` was an ordinary object type, which is exactly why the property case
 * needed a second guard rather than the same one.
 *
 * The branch must come FIRST in WireShape's chain. `any` in the checked position of
 * `T extends WireLeaf ? …` resolves to the UNION of both branches, so any later test would
 * answer both ways at once and the mapping would never be reached.
 *
 * An interface rather than `declare const wireAny: unique symbol` plus `typeof wireAny`: both
 * give the same nominal behaviour, but the const form is a value this module never uses and
 * `@typescript-eslint/no-unused-vars` reports it as such ("defined but only used as a type").
 */
interface WireAny {
  readonly brand: unique symbol;
}

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
 * What survives, therefore, is the part of the contract TypeScript can carry: the set of
 * property names, whether each one may be omitted, and each one's value type - recursively,
 * so a rename nested inside `SessionResource.user` fails too.
 *
 * What does NOT survive is every value CONSTRAINT, and that is this harness's real blind
 * spot rather than a detail. openapi.json records `maxLength: 254`, `minLength: 64`,
 * `format: email` and the platform enum; openapi-typescript erases all but the enum, so
 * `email` and `display_name` are both bare `string` in src/types/api.ts. Changing `max:254`
 * to `max:320` upstream therefore fails nothing at this level, however strict
 * WireEquivalent is. The lengths, caps and enums the Zod schemas encode are checked against
 * openapi.json by a runtime test instead - "the contract's own constraints" in auth.test.ts -
 * because that document is where those constraints actually live. Nothing on this line
 * proves them.
 *
 * One conflation is accepted and recorded: a REQUIRED property typed `T | undefined` is
 * indistinguishable here from an optional `T`, because `undefined extends T[K]` is the only
 * way to read optionality off an indexed access. On the wire the two agree - both may
 * serialize to an absent key - and openapi-typescript never emits the former anyway. If a
 * future generator does, this is the line to revisit.
 *
 * `any` does not survive either, and it is the one input this helper REFUSES rather than
 * describes. An `any` anywhere - the whole type, or one property - makes an unguarded
 * structural comparison answer true in both directions, so a proof over it passes while
 * proving nothing. The whole-type case is caught by Assignable's IsAny guard before this
 * helper has any bearing; the property case is caught here, by mapping it to WireAny. The
 * practical effect is that a contract field that degrades to `any` upstream FAILS its proof
 * rather than quietly satisfying it. proof.ts holds both negative cases.
 *
 * `Required<T>` plus a blanket undefined strip is the shortcut to avoid: it makes
 * `{ locale?: string }` and `{ locale: string }` compare equal and silently discards the
 * required-versus-optional half of the contract, which is the difference between a client
 * that may omit a field and one that must send it. proof.ts asserts, in both directions,
 * that this helper refuses that conflation.
 */
export type WireShape<T> =
  IsAny<T> extends true
    ? WireAny
    : T extends WireLeaf
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
