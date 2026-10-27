<?php

declare(strict_types=1);

use App\Domain\Plans\Capability;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanPermissions;

// No RefreshDatabase, no TestCase, no container: spec section 12 puts the permission matrix in
// the domain unit tests precisely so it can be reasoned about without a database.
// tests/Pest.php binds TestCase only to Feature, so this file runs on plain PHPUnit, which is
// the point.

it('grants access only to an active member - invited, left and removed grant nothing', function (): void {
    expect(PlanPermissions::grantsAccess(MemberStatus::Active))->toBeTrue();
    expect(PlanPermissions::grantsAccess(MemberStatus::Invited))->toBeFalse();
    expect(PlanPermissions::grantsAccess(MemberStatus::Left))->toBeFalse();
    expect(PlanPermissions::grantsAccess(MemberStatus::Removed))->toBeFalse();
});

it('decides access for every member status, so a new status cannot be added silently', function (): void {
    // No ->throwsNoExceptions(): that modifier calls expectNotToPerformAssertions(), so
    // PHPUnit marks this test RISKY - RotationPolicyTest.php's pattern. The four expect()
    // calls inside the loop are the assertions, and an unhandled case still fails this test on
    // its own: adding `case Probe;` to MemberStatus produces "UnhandledMatchError: Unhandled
    // match case App\Domain\Plans\MemberStatus::Probe" here.
    foreach (MemberStatus::cases() as $status) {
        expect(PlanPermissions::grantsAccess($status))->toBeBool();
    }
});

it('matches blueprint section 9 permission matrix exactly, across all three roles and sixteen capabilities', function (): void {
    // One row per (Capability, MemberRole) pair, 16 x 3 = 48, in Capability::cases() order.
    // true where the matrix's column for that role (or a stronger one, under the owner
    // contains admin contains member decision) lists the row, false everywhere else.
    // PROVISIONAL: CreateEvent is pinned role-gated-only here - blueprint section 9 says "if
    // permitted" and no column holds that condition yet; the Events cycle adds it together
    // with plan_events.
    $matrix = [
        [Capability::EditPlan, MemberRole::Owner, true],
        [Capability::EditPlan, MemberRole::Admin, true],
        [Capability::EditPlan, MemberRole::Member, false],
        [Capability::DeletePlan, MemberRole::Owner, true],
        [Capability::DeletePlan, MemberRole::Admin, false],
        [Capability::DeletePlan, MemberRole::Member, false],
        [Capability::ManageMembers, MemberRole::Owner, true],
        [Capability::ManageMembers, MemberRole::Admin, false],
        [Capability::ManageMembers, MemberRole::Member, false],
        [Capability::AssignAdmins, MemberRole::Owner, true],
        [Capability::AssignAdmins, MemberRole::Admin, false],
        [Capability::AssignAdmins, MemberRole::Member, false],
        [Capability::RemoveMembers, MemberRole::Owner, true],
        [Capability::RemoveMembers, MemberRole::Admin, true],
        [Capability::RemoveMembers, MemberRole::Member, false],
        [Capability::ManagePlanSettings, MemberRole::Owner, true],
        [Capability::ManagePlanSettings, MemberRole::Admin, false],
        [Capability::ManagePlanSettings, MemberRole::Member, false],
        [Capability::InviteMembers, MemberRole::Owner, true],
        [Capability::InviteMembers, MemberRole::Admin, true],
        [Capability::InviteMembers, MemberRole::Member, false],
        [Capability::ManageTasks, MemberRole::Owner, true],
        [Capability::ManageTasks, MemberRole::Admin, true],
        [Capability::ManageTasks, MemberRole::Member, false],
        [Capability::ModerateChat, MemberRole::Owner, true],
        [Capability::ModerateChat, MemberRole::Admin, true],
        [Capability::ModerateChat, MemberRole::Member, false],
        [Capability::ViewPlan, MemberRole::Owner, true],
        [Capability::ViewPlan, MemberRole::Admin, true],
        [Capability::ViewPlan, MemberRole::Member, true],
        [Capability::SendMessages, MemberRole::Owner, true],
        [Capability::SendMessages, MemberRole::Admin, true],
        [Capability::SendMessages, MemberRole::Member, true],
        [Capability::CreateTasks, MemberRole::Owner, true],
        [Capability::CreateTasks, MemberRole::Admin, true],
        [Capability::CreateTasks, MemberRole::Member, true],
        [Capability::AssignSelf, MemberRole::Owner, true],
        [Capability::AssignSelf, MemberRole::Admin, true],
        [Capability::AssignSelf, MemberRole::Member, true],
        [Capability::CompleteAssignedTasks, MemberRole::Owner, true],
        [Capability::CompleteAssignedTasks, MemberRole::Admin, true],
        [Capability::CompleteAssignedTasks, MemberRole::Member, true],
        [Capability::CreateEvent, MemberRole::Owner, true],
        [Capability::CreateEvent, MemberRole::Admin, true],
        [Capability::CreateEvent, MemberRole::Member, true],
        [Capability::SuggestTimes, MemberRole::Owner, true],
        [Capability::SuggestTimes, MemberRole::Admin, true],
        [Capability::SuggestTimes, MemberRole::Member, true],
    ];

    // Derived, not hardcoded: a literal 48 stays correct-looking even after a seventeenth
    // Capability is added, because this table is never grown to match - it would just keep
    // testing the sixteen it already knows about. count(Capability::cases()) *
    // count(MemberRole::cases()) ties this count to the same source of truth EnumTest.php
    // pins, so the table falling out of sync with the enum fails here, not silently. (It does
    // not, by itself, catch a duplicated row masking an omitted one while the enum stays at
    // sixteen - see "Fix round 1" in task-2-report.md for the measurement.) tests/Pest.php has
    // no failOnRisky (section 0.4 of this plan), so a table that silently lost rows to a bad
    // merge would still report green on the comparison below without this - the count is
    // checked before that comparison runs on what could otherwise be a thinned table.
    expect($matrix)->toHaveCount(count(Capability::cases()) * count(MemberRole::cases()));

    // Set equality over the (Capability, MemberRole) pairs, not just a count (Fix round 2):
    // a count alone cannot tell 48 distinct pairs apart from 47 plus a duplicate, so a
    // duplicated row paired with a different omitted row left the count above, and the
    // exhaustiveness loop below, both green - measured, see task-2-report.md. The expected
    // side is built from the enums rather than typed out again, so it cannot drift from
    // Capability::cases()/MemberRole::cases() independently of the table above.
    $actualPairs = array_map(fn (array $row): string => $row[0]->name.'/'.$row[1]->value, $matrix);
    $expectedPairs = [];
    foreach (Capability::cases() as $capability) {
        foreach (MemberRole::cases() as $role) {
            $expectedPairs[] = $capability->name.'/'.$role->value;
        }
    }
    sort($actualPairs);
    sort($expectedPairs);
    expect($actualPairs)->toBe($expectedPairs);

    $actual = [];
    $expected = [];

    foreach ($matrix as [$capability, $role, $allowed]) {
        $actual[] = PlanPermissions::allows($role, $capability);
        $expected[] = $allowed;
    }

    expect($actual)->toBe($expected);
});

it('decides access for every capability and role pair, so a new capability cannot be added silently', function (): void {
    // Same pattern as the MemberStatus exhaustiveness test above: toBeBool() is tautological
    // against allows()'s declared return type. The real force is UnhandledMatchError - the
    // outer match over Capability has no default arm, so a capability that the 48-row table
    // above (hand-written, not derived from Capability::cases()) forgot still gets called
    // here, and throws rather than silently returning a wrong answer.
    foreach (Capability::cases() as $capability) {
        foreach (MemberRole::cases() as $role) {
            expect(PlanPermissions::allows($role, $capability))->toBeBool();
        }
    }
});

it('is additive, not exclusive - Owner never loses what Admin has, and Admin never loses what Member has', function (): void {
    // Section 3.1's role hierarchy, stated as an invariant rather than left to emerge from the
    // 48 literals above. A future edit that grants Admin a capability Owner lacks, or grants
    // Member one Admin lacks, fails this test by name instead of silently inverting the
    // hierarchy the matrix table would still happen to read correctly row by row.
    foreach (Capability::cases() as $capability) {
        expect(PlanPermissions::allows(MemberRole::Owner, $capability) || ! PlanPermissions::allows(MemberRole::Admin, $capability))->toBeTrue();
        expect(PlanPermissions::allows(MemberRole::Admin, $capability) || ! PlanPermissions::allows(MemberRole::Member, $capability))->toBeTrue();
    }
});

it('pins CreateEvent as role-gated only - PROVISIONAL, blueprint section 9 says "if permitted" and no column holds the condition; the Events cycle adds it with plan_events', function (): void {
    expect(PlanPermissions::allows(MemberRole::Owner, Capability::CreateEvent))->toBeTrue();
    expect(PlanPermissions::allows(MemberRole::Admin, Capability::CreateEvent))->toBeTrue();
    expect(PlanPermissions::allows(MemberRole::Member, Capability::CreateEvent))->toBeTrue();
});

it('matches blueprint section 3 point 3 - an admin may remove a member, only the owner may remove an admin, nobody removes the owner', function (): void {
    $matrix = [
        [MemberRole::Owner, MemberRole::Admin, true],
        [MemberRole::Owner, MemberRole::Member, true],
        [MemberRole::Owner, MemberRole::Owner, false],
        [MemberRole::Admin, MemberRole::Member, true],
        [MemberRole::Admin, MemberRole::Owner, false],
        [MemberRole::Admin, MemberRole::Admin, false],
        [MemberRole::Member, MemberRole::Owner, false],
        [MemberRole::Member, MemberRole::Admin, false],
        [MemberRole::Member, MemberRole::Member, false],
    ];

    // Derived, not hardcoded: see the Capability matrix's matching comment above - a literal 9
    // stays correct-looking even if MemberRole ever grew a fourth case.
    expect($matrix)->toHaveCount(count(MemberRole::cases()) ** 2);

    // Set equality over the (actor, target) pairs, not just a count (Fix round 1) - the exact
    // hole Task 2's own Fix round 2 closed for allows(), reopened here: a count alone cannot
    // tell 9 distinct pairs apart from 8 plus a duplicate, so deleting
    // [MemberRole::Admin, MemberRole::Admin, false] and duplicating
    // [MemberRole::Admin, MemberRole::Member, true] in its place - which is the one row this
    // whole task's admin-versus-admin asymmetry turns on - left the count above, and the
    // toBe() below, both green; measured, see "Fix round 1" in task-3-report.md. The expected
    // side is built from MemberRole::cases() rather than typed out again, so it cannot drift
    // from the matrix above independently.
    $actualPairs = array_map(fn (array $row): string => $row[0]->value.'/'.$row[1]->value, $matrix);
    $expectedPairs = [];
    foreach (MemberRole::cases() as $actor) {
        foreach (MemberRole::cases() as $target) {
            $expectedPairs[] = $actor->value.'/'.$target->value;
        }
    }
    sort($actualPairs);
    sort($expectedPairs);
    expect($actualPairs)->toBe($expectedPairs);

    $actual = [];
    $expected = [];

    foreach ($matrix as [$actor, $target, $allowed]) {
        $actual[] = PlanPermissions::canRemoveMember($actor, $target);
        $expected[] = $allowed;
    }

    expect($actual)->toBe($expected);
});

it('decides removal for every actor and target pair, so a fourth MemberRole cannot be added silently', function (): void {
    // Same pattern as allows()'s own exhaustiveness test above: toBeBool() is tautological
    // against canRemoveMember()'s declared return type. The real force is UnhandledMatchError -
    // both the outer match (over $actor) and every inner match (over $target) have no default
    // arm, so a fourth MemberRole reaches this loop directly, independently of the 9-row table
    // above, and throws rather than silently going uncalled by anything in this file.
    foreach (MemberRole::cases() as $actor) {
        foreach (MemberRole::cases() as $target) {
            expect(PlanPermissions::canRemoveMember($actor, $target))->toBeBool();
        }
    }
});

it('lets only the owner change a role, and never the owners own role, regardless of the target role asked for', function (): void {
    // $to ranges over all three MemberRole cases at every (actor, target) pair below, which is
    // what proves canChangeRole really is $to-invariant rather than coincidentally untested at
    // the one $to value that would have exposed a bug - the class docblock's claim, pinned.
    $matrix = [];

    foreach (MemberRole::cases() as $actor) {
        foreach (MemberRole::cases() as $target) {
            foreach (MemberRole::cases() as $to) {
                $matrix[] = [
                    $actor,
                    $target,
                    $to,
                    // $actor === Owner is section 8.2's refusal 1 (owner-only); $target !==
                    // Owner is refusal 2 (never the owner's own role) - refusal 3 is subsumed
                    // by 1, and refusal 4 (a no-op) is the caller's short-circuit, not refused.
                    $actor === MemberRole::Owner && $target !== MemberRole::Owner,
                ];
            }
        }
    }

    // Explicit count: 3 actors x 3 targets x 3 "to" roles.
    expect($matrix)->toHaveCount(27);

    $actual = [];
    $expected = [];

    foreach ($matrix as [$actor, $target, $to, $allowed]) {
        $actual[] = PlanPermissions::canChangeRole($actor, $target, $to);
        $expected[] = $allowed;
    }

    expect($actual)->toBe($expected);
});

it('lets the owner invite at admin or member role, and restricts the admin to member-role invites only', function (): void {
    // Scoped to the two roles a FormRequest actually lets anyone invite at (admin, member) -
    // spec section 10.4 constrains the role field to those two for every actor, so this is the
    // 3 x 2 matrix section 17.1 names, not the full 3 x 3.
    $matrix = [
        [MemberRole::Owner, MemberRole::Admin, true],
        [MemberRole::Owner, MemberRole::Member, true],
        [MemberRole::Admin, MemberRole::Admin, false],
        [MemberRole::Admin, MemberRole::Member, true],
        [MemberRole::Member, MemberRole::Admin, false],
        [MemberRole::Member, MemberRole::Member, false],
    ];

    // Derived, not hardcoded: 3 actor roles x the two invitable roles, not the full 3 x 3 - see
    // the comment above.
    expect($matrix)->toHaveCount(count(MemberRole::cases()) * 2);

    // Set equality over the (actor, invited) pairs, not just a count (Fix round 1) - the same
    // hole as canRemoveMember's table above, and Task 2's Fix round 2 before that: a count
    // alone cannot tell 6 distinct pairs apart from 5 plus a duplicate. $invitable is typed
    // out rather than taken from MemberRole::cases(), because the restriction to two of the
    // three roles really is the point of this table - the full 3 x 3 is deliberately out of
    // scope here and pinned separately by the dedicated owner-role test below.
    $invitable = [MemberRole::Admin, MemberRole::Member];
    $actualPairs = array_map(fn (array $row): string => $row[0]->value.'/'.$row[1]->value, $matrix);
    $expectedPairs = [];
    foreach (MemberRole::cases() as $actor) {
        foreach ($invitable as $invited) {
            $expectedPairs[] = $actor->value.'/'.$invited->value;
        }
    }
    sort($actualPairs);
    sort($expectedPairs);
    expect($actualPairs)->toBe($expectedPairs);

    $actual = [];
    $expected = [];

    foreach ($matrix as [$actor, $invited, $allowed]) {
        $actual[] = PlanPermissions::canInviteWithRole($actor, $invited);
        $expected[] = $allowed;
    }

    expect($actual)->toBe($expected);
});

it('refuses an owner-role invite from every actor, defensively, even though the FormRequest never offers the choice', function (): void {
    // Not part of the 3 x 2 matrix above - the matrix only covers the two roles a FormRequest
    // can submit. This is the third, defensive row: an owner-role invite would be an
    // ownership transfer bypassing the transfer endpoint's eligibility checks, so this
    // predicate refuses it independently of which role is asking, including the owner's own.
    foreach (MemberRole::cases() as $actor) {
        expect(PlanPermissions::canInviteWithRole($actor, MemberRole::Owner))->toBeFalse();
    }
});

it('matches blueprint section 9 point 2 - only the owner cannot leave, reduced to one argument because the schema permits exactly one owner', function (): void {
    expect(PlanPermissions::canLeave(MemberRole::Owner))->toBeFalse();
    expect(PlanPermissions::canLeave(MemberRole::Admin))->toBeTrue();
    expect(PlanPermissions::canLeave(MemberRole::Member))->toBeTrue();
});

it('decides leaving for every role, so a fourth MemberRole cannot be added silently', function (): void {
    // Same pattern as allows()'s own exhaustiveness test above: toBeBool() is tautological
    // against canLeave()'s declared return type. The real force is UnhandledMatchError - the
    // match has no default arm, so a fourth MemberRole reaches this loop and throws, rather
    // than never being called by anything in this file (the three literals above are each a
    // fixed case, not a loop over MemberRole::cases()).
    foreach (MemberRole::cases() as $role) {
        expect(PlanPermissions::canLeave($role))->toBeBool();
    }
});

it('restricts archiving to the owner, the same split as deleting', function (): void {
    expect(PlanPermissions::canArchive(MemberRole::Owner))->toBeTrue();
    expect(PlanPermissions::canArchive(MemberRole::Admin))->toBeFalse();
    expect(PlanPermissions::canArchive(MemberRole::Member))->toBeFalse();
});

it('decides archiving for every role, so a fourth MemberRole cannot be added silently', function (): void {
    // Same pattern and same reason as canLeave's exhaustiveness test above.
    foreach (MemberRole::cases() as $role) {
        expect(PlanPermissions::canArchive($role))->toBeBool();
    }
});
