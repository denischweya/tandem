import type { Assignable, Equivalent, Expect } from './assignability';

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
