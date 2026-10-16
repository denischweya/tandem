<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Shared\Clock;
use App\Models\PlanActivity;
use InvalidArgumentException;

final readonly class RecordActivity
{
    /**
     * 'User' as a string subject type is OutboxWriter's existing convention
     * (write('User', $id, ...)), not a new one. These are constants rather than two literals
     * at nine call sites because the value is matched against the derivation below, so a
     * typo at a call site is a 500 rather than a mis-filed row.
     */
    public const SUBJECT_USER = 'User';

    public const SUBJECT_INVITE = 'PlanInvite';

    public function __construct(private Clock $clock) {}

    /**
     * The only INSERT into plan_activity in the application (spec §11.2). Nine use cases write
     * activity; a tenth that writes it inline is the one that gets created_at wrong.
     *
     * Three mechanics this method owns, each a measured hazard rather than a style preference:
     *
     * created_at is set explicitly from Clock. PlanActivity has $timestamps = false - there is
     * no update path for an immutable record, so there is nothing for updated_at to mean - and
     * the column is NOT NULL with no default, so Eloquent fills in nothing and an omission is a
     * 23502, not a silent now(). PlanActivityFactory's docblock exists only to record this.
     *
     * There is NO DB::transaction() here, and that is the point. The caller already has one
     * open and the row has to be inside it, so a Plan that was not created has no "created"
     * row and the feed can never describe something that did not happen. Register::execute()
     * applies the same rule to its outbox event, in the same words.
     *
     * There is no update path and no delete path. Blueprint §51: "activity records should be
     * immutable." No use case, no route, no repository method. The one exception in the whole
     * codebase is §9.3 step 4's anonymisation UPDATE in DeleteAccount, which nulls actor_id and
     * removes nothing.
     *
     * The three arguments after $type are checked against spec §11.1's table rather than
     * trusted. They are never client-supplied - every caller derives them from a row it has
     * just read or written - so a disagreement is a programming error, and InvalidArgumentException
     * with no render closure in bootstrap/app.php is a 500, which is the correct answer to one.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        string $planId,
        ?string $actorId,
        ActivityType $type,
        ?string $subjectType,
        ?string $subjectId,
        array $metadata,
    ): void {
        [$expectedSubjectType, $expectedKeys] = self::shapeOf($type);

        if ($expectedSubjectType === null && ($subjectType !== null || $subjectId !== null)) {
            throw new InvalidArgumentException(
                "Activity {$type->value} carries no subject, so subject_type and subject_id must both be null."
            );
        }

        if ($expectedSubjectType !== null && ($subjectType !== $expectedSubjectType || $subjectId === null)) {
            throw new InvalidArgumentException(
                "Activity {$type->value} requires subject_type '{$expectedSubjectType}' and a non-null subject_id."
            );
        }

        $keys = array_keys($metadata);
        sort($keys);
        sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            // The continuation line leads with the dot and the call takes no trailing comma,
            // which is RequiresAuthenticatedUser's exact shape - the one multi-line throw
            // already in the codebase - so Pint's Laravel preset leaves this alone.
            throw new InvalidArgumentException(
                "Activity {$type->value} requires metadata keys [".implode(', ', $expectedKeys)
                .'], got ['.implode(', ', $keys).'].'
            );
        }

        PlanActivity::query()->create([
            'plan_id' => $planId,
            'actor_id' => $actorId,
            'activity_type' => $type->value,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * Spec §11.1's two tables, as one match: the subject type each activity points at, and the
     * exact metadata keys it carries.
     *
     * match, not a chain of ifs and not a lookup array: a case added to ActivityType without
     * being handled here throws UnhandledMatchError rather than falling through to a default
     * that would write an unconstrained row. plan_activity.activity_type has no CHECK and no
     * column default (measured: ConstraintTest's column-default case covers five
     * plans/plan_members/plan_invites pairs and none on plan_activity), so this match and the
     * enum are the only things in the system that constrain the vocabulary.
     *
     * Honest accounting of what proves that red (vacuous-guard review, 2026-10-16, B5c): no
     * shipped test reaches this match with a case it does not handle. RecordActivityTest's own
     * completeness check (cases() vs array_keys($shapes)) runs before the loop that calls
     * execute(), so a case missing from that test's fixture reds there first, never reaching
     * this method at all - and a case present in the fixture is, by construction, one this
     * match already handles. The only way to observe THIS line's UnhandledMatchError is to add
     * a case to the enum, add a matching fixture row so the completeness check still agrees,
     * and deliberately leave this match without an arm for it - which is a break step performed
     * during implementation, not a standing assertion in the suite.
     *
     * Two decisions visible in the arms. plan.updated carries field NAMES and never values:
     * plan_activity is immutable and readable by every member, so a value written here is
     * permanently visible to everyone who can read the Plan, and a later cycle adding a field
     * that is not uniformly visible would make every past value of it permanently public.
     * member.invited carries the channel and the role and never the invited address: the feed
     * is read by every member, an invited address is personal data about someone who is not a
     * member (blueprint §126), and the address-bearing view is GET /plans/{plan}/invites.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private static function shapeOf(ActivityType $type): array
    {
        return match ($type) {
            ActivityType::PlanCreated, ActivityType::PlanArchived => [null, []],

            ActivityType::PlanUpdated => [null, ['fields']],

            ActivityType::MemberJoined,
            ActivityType::MemberLeft,
            ActivityType::MemberRemoved => [self::SUBJECT_USER, []],

            ActivityType::MemberRoleChanged => [self::SUBJECT_USER, ['from', 'to']],

            ActivityType::MemberInvited => [self::SUBJECT_INVITE, ['channel', 'role']],

            ActivityType::InviteRevoked => [self::SUBJECT_INVITE, []],
        };
    }
}
