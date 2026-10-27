/**
 * `Intl.DateTimeFormat().resolvedOptions().timeZone` is what `IanaTimeZone` validates against
 * server-side - `RegisterSchema.timezone`'s own docblock in `@tandem/shared` records that the
 * client has no equivalent check worth running, since a browser's accepted zone set depends on
 * its own ICU build. Mirrors `tandem-mobile/src/features/auth/screenLogic.ts`'s
 * `resolveTimezone` exactly: on a minimal ICU build this can come back `undefined` or empty,
 * and 'UTC' is itself a valid IANA zone, so falling back to it never sends the empty string
 * `ConvertEmptyStringsToNull` would turn into a 422. Nothing in `src/` reads the result today
 * beyond this form sending it once at registration - there is no Profile page in `tandem-web`
 * this cycle, so the sign-up form says "detected automatically", not "change it in Profile
 * later" the way mobile's copy can, because that page does not exist on web yet.
 */
export function resolveTimezone(): string {
  const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  return typeof zone === 'string' && zone.length > 0 ? zone : 'UTC';
}
