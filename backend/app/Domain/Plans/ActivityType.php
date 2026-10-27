<?php

declare(strict_types=1);

namespace App\Domain\Plans;

// This file's rationale is in line comments, not in the class docblock below, and that is a
// measured constraint rather than a preference. Scramble's
// EnumTransformer::addEnumDescription() calls ReflectionEnum::getDocComment(), flattens the
// newlines out of it and prepends the result to the published schema description -
// unconditionally, gated on no config value. Wave 2 types no Resource field and no rules() key
// as a native enum, so nothing publishes this enum today; a class docblock is nonetheless the
// one place where breaking that rule once would ship this file's internal reasoning to every
// API client, and MemberRole's docblock is already a leak waiting for exactly that mistake. A
// line comment is not a doc comment, so getDocComment() never returns these lines.
//
// Nine cases, spec 11.1, and no tenth without a decision. `plan.created` is already the literal
// PlanActivityFactory seeds, so the vocabulary is continued rather than invented. Case order is
// the lifecycle of a Plan followed by the lifecycle of one person's membership of it, which is
// the order MemberStatus's docblock already reads in.
//
// There is deliberately no `plan.deleted`: a hard delete CASCADEs plan_activity away, so there
// is no row left for such a case to live on. Recorded because its absence looks like an
// omission.
//
// This enum carries no method, which is also the state of all seven enums the codebase already
// has. Spec 11.1's subject_type/subject_id mapping - 'User' for the four member.* rows that name
// a person, 'PlanInvite' for member.invited and invite.revoked, NULL for the three plan.* rows -
// belongs to RecordActivity, the single writer of plan_activity. A Domain enum returning the
// string 'PlanInvite' would hardcode a model name in the one layer that imports nothing.
/**
 * The kinds of activity a Plan's feed can contain.
 */
enum ActivityType: string
{
    case PlanCreated = 'plan.created';
    case PlanUpdated = 'plan.updated';
    case PlanArchived = 'plan.archived';
    case MemberInvited = 'member.invited';
    case MemberJoined = 'member.joined';
    case MemberLeft = 'member.left';
    case MemberRemoved = 'member.removed';
    case MemberRoleChanged = 'member.role_changed';
    case InviteRevoked = 'invite.revoked';
}
