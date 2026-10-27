/**
 * Joins a list of names the way a sentence would say them aloud:
 * "Denis", "Denis and Sarah", "Denis, Sarah and James" — no Oxford comma
 * before the final "and", matching the invite copy style.
 */
export function joinWithAnd(items: readonly string[]): string {
  if (items.length === 0) return '';
  // The length check above guarantees index 0 exists; `noUncheckedIndexedAccess` cannot see
  // that through a computed index (finding I6, spec §14.1), so fall back explicitly rather
  // than asserting it away.
  if (items.length === 1) return items[0] ?? '';
  if (items.length === 2) return `${items[0]} and ${items[1]}`;

  const allButLast = items.slice(0, -1).join(', ');
  const last = items[items.length - 1];
  return `${allButLast} and ${last}`;
}
