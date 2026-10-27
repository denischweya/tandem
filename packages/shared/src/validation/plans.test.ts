import { describe, expect, it } from 'vitest';

import {
  ChangeMemberRoleRequestSchema,
  CreatePlanRequestSchema,
  ErrorEnvelopeSchema,
  InvitePlanMemberRequestSchema,
  JoinPlanRequestSchema,
  PlanActivityResourceSchema,
  PlanInviteCreatedResourceSchema,
  PlanPageSchema,
  UpdatePlanRequestSchema,
} from './plans';

/**
 * One case per RULE boundary rather than one per schema. The Expect<...> assertions in plans.ts
 * already prove the SHAPES agree with the contract and no runtime test can add to that; what
 * they cannot prove is that a length, an enum or a nullability matches the API's, which is the
 * only thing left for these.
 */

const validPlan = {
  title: 'Birthday weekend',
  timezone: 'Europe/London',
};

describe('CreatePlanRequestSchema', () => {
  it('accepts a title at the 255-character limit and rejects 256', () => {
    expect(
      CreatePlanRequestSchema.safeParse({ ...validPlan, title: 'a'.repeat(255) }).success,
    ).toBe(true);
    expect(
      CreatePlanRequestSchema.safeParse({ ...validPlan, title: 'a'.repeat(256) }).success,
    ).toBe(false);
  });

  it('accepts a description at the 2000-character limit and rejects 2001', () => {
    expect(
      CreatePlanRequestSchema.safeParse({ ...validPlan, description: 'a'.repeat(2000) }).success,
    ).toBe(true);
    expect(
      CreatePlanRequestSchema.safeParse({ ...validPlan, description: 'a'.repeat(2001) }).success,
    ).toBe(false);
  });

  it('rejects a whitespace-only title, which `required` also rejects upstream', () => {
    // TrimStrings runs before `required` on the server, so '   ' is a 422 there. `.trim()` ahead
    // of `.min(1)` is what makes that true here; the other order accepts it and returns ''.
    expect(CreatePlanRequestSchema.safeParse({ ...validPlan, title: '   ' }).success).toBe(false);
  });

  it('rejects an omitted timezone, which the API requires rather than defaulting', () => {
    expect(CreatePlanRequestSchema.safeParse({ title: 'Birthday weekend' }).success).toBe(false);
  });
});

describe('UpdatePlanRequestSchema', () => {
  it('accepts a title at the 255-character limit and rejects 256', () => {
    expect(UpdatePlanRequestSchema.safeParse({ title: 'a'.repeat(255) }).success).toBe(true);
    expect(UpdatePlanRequestSchema.safeParse({ title: 'a'.repeat(256) }).success).toBe(false);
  });

  it('accepts a description at the 2000-character limit and rejects 2001', () => {
    expect(UpdatePlanRequestSchema.safeParse({ description: 'a'.repeat(2000) }).success).toBe(true);
    expect(UpdatePlanRequestSchema.safeParse({ description: 'a'.repeat(2001) }).success).toBe(
      false,
    );
  });

  it('rejects a whitespace-only timezone, which is also a 422 upstream when sent', () => {
    // Every field here is optional - omitting it leaves the value unchanged - but `.trim()`
    // ahead of `.min(1)` is what makes a SENT empty value a rejection rather than a silent no-op,
    // matching ConvertEmptyStringsToNull turning '' into null before `required` runs upstream.
    expect(UpdatePlanRequestSchema.safeParse({ timezone: '   ' }).success).toBe(false);
  });
});

describe('ChangeMemberRoleRequestSchema', () => {
  it('accepts all three roles and rejects anything else', () => {
    for (const role of ['owner', 'admin', 'member']) {
      expect(ChangeMemberRoleRequestSchema.safeParse({ role }).success).toBe(true);
    }

    expect(ChangeMemberRoleRequestSchema.safeParse({ role: 'guest' }).success).toBe(false);
  });
});

describe('InvitePlanMemberRequestSchema', () => {
  it('accepts an empty body, which is a link-only invite', () => {
    expect(InvitePlanMemberRequestSchema.safeParse({}).success).toBe(true);
  });

  it('rejects an owner-role invite, which would be an ownership transfer by the back door', () => {
    expect(InvitePlanMemberRequestSchema.safeParse({ role: 'owner' }).success).toBe(false);
    expect(InvitePlanMemberRequestSchema.safeParse({ role: 'admin' }).success).toBe(true);
  });

  it('accepts an address at the 254-character limit and rejects 255', () => {
    const domain = '@example.test';
    const address = (length: number): string => 'a'.repeat(length - domain.length) + domain;

    expect(InvitePlanMemberRequestSchema.safeParse({ email: address(254) }).success).toBe(true);
    expect(InvitePlanMemberRequestSchema.safeParse({ email: address(255) }).success).toBe(false);
  });
});

describe('JoinPlanRequestSchema', () => {
  it('accepts a padded 64-character token and rejects 63', () => {
    const token = 'a'.repeat(64);

    const parsed = JoinPlanRequestSchema.safeParse({ token: `  ${token}  ` });

    expect(parsed.success).toBe(true);
    expect(parsed.success && parsed.data.token).toBe(token);
    expect(JoinPlanRequestSchema.safeParse({ token: 'a'.repeat(63) }).success).toBe(false);
  });
});

describe('PlanPageSchema', () => {
  const page = { data: [], meta: { next_cursor: null } };

  it('accepts a null next_cursor, which is the end of the list', () => {
    expect(PlanPageSchema.safeParse(page).success).toBe(true);
  });

  it('rejects an absent next_cursor, which is a different contract from a null one', () => {
    expect(PlanPageSchema.safeParse({ data: [], meta: {} }).success).toBe(false);
  });

  it('rejects an absent meta, which is what a list that forgot ->additional() would send', () => {
    expect(PlanPageSchema.safeParse({ data: [] }).success).toBe(false);
  });
});

describe('PlanInviteCreatedResourceSchema', () => {
  const invite = {
    id: '018f0f4e-0000-7000-8000-000000000003',
    email: 'invitee@example.test',
    role: 'member',
    expires_at: '2026-10-10T09:00:00.000+00:00',
    accepted_at: null,
    revoked_at: null,
    invited_by_name: 'Ada Lovelace',
    token: 'a'.repeat(64),
  };

  it('accepts a 64-character token and rejects 63', () => {
    // The server mints bin2hex(random_bytes(32)) - 64 lowercase hex characters - so this is the
    // UserSchema.id argument plans.ts records: a different length is impossible, not merely
    // unexpected, and openapi.json states no constraint for this field at all.
    expect(PlanInviteCreatedResourceSchema.safeParse(invite).success).toBe(true);
    expect(
      PlanInviteCreatedResourceSchema.safeParse({ ...invite, token: 'a'.repeat(63) }).success,
    ).toBe(false);
  });
});

describe('PlanActivityResourceSchema', () => {
  const row = {
    id: '018f0f4e-0000-7000-8000-000000000001',
    activity_type: 'member.role_changed',
    actor_id: null,
    actor_name: null,
    subject_type: 'User',
    subject_id: '018f0f4e-0000-7000-8000-000000000002',
    metadata: { from: 'member', to: 'admin' },
    created_at: '2026-10-10T09:00:00.000+00:00',
  };

  it('accepts every metadata shape the writer produces', () => {
    for (const metadata of [
      { from: 'member', to: 'admin' },
      { fields: ['title', 'description'] },
      { channel: 'link', role: 'member' },
      {},
    ]) {
      expect(PlanActivityResourceSchema.safeParse({ ...row, metadata }).success).toBe(true);
    }
  });

  it('rejects a metadata value that is neither a string nor a list of strings', () => {
    expect(PlanActivityResourceSchema.safeParse({ ...row, metadata: { count: 3 } }).success).toBe(
      false,
    );
  });

  it('accepts an anonymised row, both offset spellings, and no bare datetime', () => {
    // `row` carries +00:00, which is what DATE_RFC3339_EXTENDED emits for UTC. Measured against
    // zod 4.6.5: the DEFAULT z.iso.datetime() REJECTS that spelling, which is why { offset: true }
    // is load-bearing rather than permissive - drop it and every activity row fails to parse.
    expect(PlanActivityResourceSchema.safeParse(row).success).toBe(true);
    expect(
      PlanActivityResourceSchema.safeParse({ ...row, created_at: '2026-10-10T09:00:00.000Z' })
        .success,
    ).toBe(true);
    // And { offset: true } does not mean "anything goes": a datetime with no zone designator at
    // all is still refused, so this stays a real check rather than a shape hint.
    expect(
      PlanActivityResourceSchema.safeParse({ ...row, created_at: '2026-10-10T09:00:00.000' })
        .success,
    ).toBe(false);
  });
});

describe('ErrorEnvelopeSchema', () => {
  it('keeps `errors` on a 422 body', () => {
    const parsed = ErrorEnvelopeSchema.safeParse({
      message: 'The given data was invalid.',
      errors: { title: ['The title field is required.'] },
    });

    // The union's order is load-bearing: ErrorMessageSchema strips unknown keys, so if it came
    // first this would parse and silently drop `errors` on every validation failure.
    expect(parsed.success && 'errors' in parsed.data).toBe(true);
  });

  it('parses a bare message body', () => {
    const parsed = ErrorEnvelopeSchema.safeParse({ message: 'This Plan has been archived.' });

    expect(parsed.success && parsed.data.message).toBe('This Plan has been archived.');
  });

  it('rejects a body with no message at all', () => {
    expect(ErrorEnvelopeSchema.safeParse({}).success).toBe(false);
  });
});
