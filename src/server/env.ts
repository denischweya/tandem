import 'server-only';

/**
 * Server-only, and the reason is PROVENANCE, not secrecy.
 *
 * Do not write down that this hides the API's address: `src/app/page.tsx` renders
 * `<p data-testid="api-base-url">API: {apiBaseUrl()}</p>` and Task 11 keeps that test id, so
 * the address is in the markup either way. Measured, and worth stating plainly so nobody
 * defends a security property this does not have.
 *
 * What the split actually buys is one authoritative, correctly-guarded server-side read.
 * `src/lib/env.ts` exposed `NEXT_PUBLIC_API_BASE_URL`, which Next inlines into the client
 * bundle - and under ADR 0004 the browser never calls the API, so a `NEXT_PUBLIC_*` variable
 * advertised an architecture this app does not have and invited a client component to fetch
 * the API directly, bypassing the cookie boundary that is the whole of ADR 0004. The
 * `server-only` import makes that a BUILD error. And it closes review finding m6 in the same
 * change: the deleted module used `??`, so `API_BASE_URL=` (set but empty) yielded `''` and
 * every fetch silently became relative.
 *
 * The guard is `typeof x === 'string' && x.length > 0`, not `??`.
 * `tandem-mobile/src/config/env.ts` guarded correctly and had two Jest tests; this one had
 * none. Both now agree, and this one is tested.
 *
 * NOTE THE SHAPE DIFFERENCE, which is deliberate and is the one place the two clients'
 * `apiBaseUrl` are not mirrors: mobile exports a `const` (it reads `Constants.expoConfig`,
 * available synchronously at module load, and the value cannot change for the life of the
 * bundle), web exports a FUNCTION (reading `process.env` at module scope in Next captures the
 * build-time value, and a server deployment must be able to be reconfigured without a
 * rebuild). Task 11 states the call-site consequence.
 */
export function apiBaseUrl(): string {
  const fromEnv = process.env.API_BASE_URL;
  const raw =
    typeof fromEnv === 'string' && fromEnv.length > 0 ? fromEnv : 'http://localhost:8000/api/v1';
  return raw.replace(/\/+$/, '');
}
