# tandem-shared

Generated API types, hand-written Zod schemas and timezone-safe date helpers, shared by
`tandem-mobile` and `tandem-web`.

Consumed as a tagged Git dependency, never from a registry:

    "@tandem/shared": "github:denischweya/tandem-shared#v0.1.0"

`src/types/api.ts` is generated from the API's `openapi.json` and must never be hand-edited.
Zod schemas are hand-written and are proven to match the generated types at compile time.
