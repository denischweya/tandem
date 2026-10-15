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

    // Explicit count: 16 capabilities x 3 roles. tests/Pest.php has no failOnRisky (section
    // 0.4 of this plan), so a table that silently lost rows to a bad merge would still report
    // green on the comparison below without this - the count is checked before that
    // comparison runs on what could otherwise be a thinned table.
    expect($matrix)->toHaveCount(48);

    $actual = [];
    $expected = [];

    foreach ($matrix as [$capability, $role, $allowed]) {
        $actual[] = PlanPermissions::allows($role, $capability);
        $expected[] = $allowed;
    }

    expect($actual)->toBe($expected);
});

it('pins CreateEvent as role-gated only - PROVISIONAL, blueprint section 9 says "if permitted" and no column holds the condition; the Events cycle adds it with plan_events', function (): void {
    expect(PlanPermissions::allows(MemberRole::Owner, Capability::CreateEvent))->toBeTrue();
    expect(PlanPermissions::allows(MemberRole::Admin, Capability::CreateEvent))->toBeTrue();
    expect(PlanPermissions::allows(MemberRole::Member, Capability::CreateEvent))->toBeTrue();
});
