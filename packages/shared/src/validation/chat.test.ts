import { describe, expect, it } from 'vitest';

import {
  AddReactionRequestSchema,
  EditMessageRequestSchema,
  PlanMessagePageSchema,
  PlanMessageResourceSchema,
  SendMessageRequestSchema,
} from './chat';

/**
 * One case per RULE boundary, the same split plans.test.ts uses: the `Expect<...>` assertions in
 * chat.ts already prove the SHAPES agree with the contract, and no runtime test can add to that.
 * What is left is whether a length, an enum or a nullability matches the API's.
 */

describe('SendMessageRequestSchema', () => {
  it('rejects an empty body and trims before measuring it', () => {
    expect(SendMessageRequestSchema.safeParse({ body: '   ' }).success).toBe(false);
    const padded = SendMessageRequestSchema.safeParse({ body: '  hi  ' });
    expect(padded.success && padded.data.body).toBe('hi');
  });

  it('rejects a body over 4000 characters', () => {
    expect(SendMessageRequestSchema.safeParse({ body: 'a'.repeat(4001) }).success).toBe(false);
    expect(SendMessageRequestSchema.safeParse({ body: 'a'.repeat(4000) }).success).toBe(true);
  });
});

describe('AddReactionRequestSchema', () => {
  it('accepts each of the five MVP reactions and rejects anything else', () => {
    for (const reaction of ['👍', '❤️', '😂', '🎉', '❓'] as const) {
      expect(AddReactionRequestSchema.safeParse({ reaction }).success).toBe(true);
    }
    expect(AddReactionRequestSchema.safeParse({ reaction: '🤠' }).success).toBe(false);
  });

  it('rejects an omitted reaction', () => {
    expect(AddReactionRequestSchema.safeParse({}).success).toBe(false);
  });
});

describe('PlanMessageResourceSchema and PlanMessagePageSchema', () => {
  it('parses a real-shaped page response', () => {
    const page = {
      data: [
        {
          id: '0199e2a0-7b1c-7000-8000-000000000099',
          plan_id: '0199e2a0-7b1c-7000-8000-000000000001',
          user_id: '0199e2a0-7b1c-7000-8000-000000000002',
          user_display_name: 'Ada Lovelace',
          body: 'Should we do Italian?',
          edited_at: null,
          created_at: '2026-10-29T12:00:00.000+00:00',
          updated_at: '2026-10-29T12:00:00.000+00:00',
          reactions: [{ reaction: '👍', count: 1, reacted_by_viewer: true }],
        },
      ],
      meta: { next_cursor: null },
    };

    expect(PlanMessagePageSchema.safeParse(page).success).toBe(true);
  });

  it('parses a message with no reactions and a non-null edited_at', () => {
    const message = {
      id: '0199e2a0-7b1c-7000-8000-000000000099',
      plan_id: '0199e2a0-7b1c-7000-8000-000000000001',
      user_id: '0199e2a0-7b1c-7000-8000-000000000002',
      user_display_name: 'Ada Lovelace',
      body: 'edited: should we do Thai instead?',
      edited_at: '2026-10-29T12:05:00.000+00:00',
      created_at: '2026-10-29T12:00:00.000+00:00',
      updated_at: '2026-10-29T12:05:00.000+00:00',
      reactions: [],
    };

    expect(PlanMessageResourceSchema.safeParse(message).success).toBe(true);
  });

  it('rejects a non-UUID id, and a timestamp without an offset', () => {
    const base = {
      id: '0199e2a0-7b1c-7000-8000-000000000099',
      plan_id: '0199e2a0-7b1c-7000-8000-000000000001',
      user_id: '0199e2a0-7b1c-7000-8000-000000000002',
      user_display_name: 'Ada Lovelace',
      body: 'Should we do Italian?',
      edited_at: null,
      created_at: '2026-10-29T12:00:00.000+00:00',
      updated_at: '2026-10-29T12:00:00.000+00:00',
      reactions: [],
    };

    expect(PlanMessageResourceSchema.safeParse({ ...base, id: 'not-a-uuid' }).success).toBe(false);
    // The anonymised author (ADR 0012): user_id null and user_display_name null together is
    // what GET /plans/{plan}/messages really returns once the sender has deleted their account,
    // so it must PARSE - a schema that rejected it would make the client's own type guard the
    // thing that breaks when somebody leaves.
    expect(
      PlanMessageResourceSchema.safeParse({ ...base, user_id: null, user_display_name: null })
        .success,
    ).toBe(true);
    // ...and a NON-uuid user_id is still refused, so `.nullable()` above did not quietly widen
    // the field to "any string".
    expect(PlanMessageResourceSchema.safeParse({ ...base, user_id: 'not-a-uuid' }).success).toBe(
      false,
    );
    // z.iso.datetime({ offset: true }) requires a numeric offset (or Z); a bare local time,
    // which this API never sends, must still be refused.
    expect(
      PlanMessageResourceSchema.safeParse({ ...base, created_at: '2026-10-29T12:00:00.000' })
        .success,
    ).toBe(false);
  });
});

describe('EditMessageRequestSchema', () => {
  it('applies the same body rule as sending', () => {
    expect(EditMessageRequestSchema.safeParse({ body: '' }).success).toBe(false);
  });

  it('rejects a body over 4000 characters', () => {
    expect(EditMessageRequestSchema.safeParse({ body: 'a'.repeat(4001) }).success).toBe(false);
    expect(EditMessageRequestSchema.safeParse({ body: 'a'.repeat(4000) }).success).toBe(true);
  });
});
