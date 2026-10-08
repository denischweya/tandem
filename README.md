# tandem-shared

Generated API types, hand-written Zod schemas and timezone-safe date helpers, shared by
`tandem-mobile` and `tandem-web`.

Consumed as a tagged Git dependency, never from a registry. Measured: the `github:owner/repo`
shorthand and the explicit `git+https://` form are equivalent here — both install, and npm
normalises either one to the shorthand in a committed `package.json` anyway. This project
writes the shorthand. `tandem-docs` ADR 0002 is authoritative on the specifier form; see it
before changing this.

    "@tandem/shared": "github:denischweya/tandem-shared#v0.1.0"

`src/types/api.ts` is generated from the API's `openapi.json` and must never be hand-edited.
Zod schemas are hand-written and are proven to match the generated types at compile time.
