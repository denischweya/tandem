import type { Assignable, Equivalent, Expect, WireEquivalent, WireShape } from './assignability';

/**
 * Regression test for the assignability harness itself.
 *
 * Positive assertions alone cannot prove a type-level guard still guards: if `Expect`
 * loses its `extends true` constraint, every positive assertion in the project keeps
 * compiling. So the negative cases use `@ts-expect-error`, which FAILS when the line
 * below it stops erroring. That inversion is the only thing that makes this file a
 * real regression test rather than decoration.
 */

// Asymmetric on purpose. Wide IS assignable to Narrow; Narrow is NOT assignable to Wide.
// A symmetrically-incompatible fixture cannot detect a reversed Assignable, which is how
// the previous version of this file missed exactly that.
type Narrow = { id: string };
type Wide = { id: string; extra: number };

export type _AssignableHoldsOneWay = Expect<Assignable<Wide, Narrow>>;

// @ts-expect-error Assignable must be FALSE in this direction. If this stops erroring,
// Assignable's direction has been reversed and every proof in the project is backwards.
export type _AssignableFailsOtherWay = Expect<Assignable<Narrow, Wide>>;

// @ts-expect-error Expect must REJECT false. If this stops erroring, Expect has lost its
// `extends true` constraint and every assertion built on it is vacuous.
export type _ExpectRejectsFalse = Expect<false>;

export type _EquivalentHolds = Expect<Equivalent<Narrow, Narrow>>;

// @ts-expect-error Equivalent must be FALSE for a pair that is not mutually assignable.
export type _EquivalentFails = Expect<Equivalent<Wide, Narrow>>;

/**
 * Regression test for WireShape and WireEquivalent.
 *
 * The fixtures are the two real representations of one optional field: the left is what
 * Zod 4's `.optional()` infers, the right is what openapi-typescript 7.13 emits. Everything
 * below is checked under this repo's `exactOptionalPropertyTypes` (spec §14.1), which is
 * what makes them differ at all.
 */
type Inferred = { id: string; locale?: string | undefined };
type Generated = { id: string; locale?: string };

// @ts-expect-error The two representations are NOT equivalent on their own - that is the
// problem WireEquivalent exists to absorb. If this stops erroring, Zod and
// openapi-typescript have converged and the normalization below has become dead weight.
export type _RepresentationsDifferRaw = Expect<Equivalent<Inferred, Generated>>;

export type _WireAbsorbsRepresentation = Expect<WireEquivalent<Inferred, Generated>>;

type RequiredLocale = { id: string; locale: string };
type RenamedField = { id: string; locale_code?: string };
type RetypedField = { id: string; locale?: number };
type ExtraField = { id: string; locale?: string; extra: boolean };

// The three ways the contract can really drift. Each must fail, or the ten assertions in
// auth.ts, users.ts and session.ts prove nothing.

// @ts-expect-error 1. A renamed field must fail.
export type _WireRejectsRename = Expect<WireEquivalent<Generated, RenamedField>>;

// @ts-expect-error 2. A retyped field must fail.
export type _WireRejectsRetype = Expect<WireEquivalent<Generated, RetypedField>>;

// @ts-expect-error 3a. An optional field made required must fail. This is the one a
// normalization that flattened optionality would pass, and it is why WireShape rewrites
// property types without touching property modifiers.
export type _WireRejectsOptionalMadeRequired = Expect<WireEquivalent<RequiredLocale, Generated>>;

// @ts-expect-error 3b. And a required field made optional must fail, in the other direction.
export type _WireRejectsRequiredMadeOptional = Expect<WireEquivalent<Generated, RequiredLocale>>;

// @ts-expect-error A property present on one side only must fail, in whichever direction.
export type _WireRejectsExtraProperty = Expect<WireEquivalent<ExtraField, Generated>>;

// Why WireEquivalent is built on Equivalent and not Assignable: one-way assignability is
// genuinely TRUE for case 3a above, so the one-way form cannot detect it. This positive
// assertion pins that gap in place - if it ever starts erroring, one-way assignability has
// become strict enough on its own and WireEquivalent's docblock needs correcting.
export type _OneWayAssignabilityMisses3a = Expect<
  Assignable<WireShape<RequiredLocale>, WireShape<Generated>>
>;

// @ts-expect-error `null` is a JSON value and must survive normalization: a nullable field
// is a different contract from a non-nullable one, so only `undefined` may be stripped.
export type _WireKeepsNull = Expect<WireEquivalent<{ a?: string | null }, { a?: string }>>;

// Nested fixtures are named rather than written inline for a mechanical reason: prettier
// wraps a long `Expect<...>` onto a second line, and `@ts-expect-error` suppresses only the
// line immediately after it - so a wrapped negative assertion silently reports "unused
// directive" instead of what it meant to assert. Short enough to stay on one line is the
// property that keeps these honest.
type NestedInferred = { user: Inferred };
type NestedGenerated = { user: Generated };
type NestedRenamed = { user: RenamedField };

// Normalization recurses, or SessionResource's nested `user` object would go unproven...
export type _WireNormalizesNested = Expect<WireEquivalent<NestedInferred, NestedGenerated>>;

// @ts-expect-error ...and a rename nested one level deep must still fail.
export type _WireRejectsNestedRename = Expect<WireEquivalent<NestedInferred, NestedRenamed>>;
