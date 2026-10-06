import type { Expect, Assignable } from './assignability';

type Target = { id: string; title: string };
type Wrong = { id: string; title: number };

// Should compile: identical shape.
export type _Good = Expect<Assignable<Target, Target>>;

// Should NOT compile: title is the wrong type.
export type _RejectsMismatch = Expect<Assignable<Wrong, Target> extends false ? true : false>;
