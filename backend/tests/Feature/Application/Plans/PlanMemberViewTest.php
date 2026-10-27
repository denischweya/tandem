<?php

declare(strict_types=1);

use App\Application\Plans\PlanMemberView;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Models\PlanMember;
use Illuminate\Support\Carbon;

// Not in Task C's own Test files list. The brief's one fromMember() test
// (PlanMemberResourceTest's throw case) never reaches the success branch at all - it exits
// through the null-joined_at throw before userId/displayName/role/status/joinedAt are ever
// assigned - so nothing the brief prescribes exercises the actual field mapping. And
// PlanMemberView has two plain `string` properties, $userId and $displayName, which
// fromMember(PlanMember $member, string $displayName) maps $member->user_id onto one and the
// parameter onto the other - a transposition between the two is invisible to the type system.
// This is the context file's named trap, third appearance on this wave: Task 10 shipped exactly
// this shape (two same-typed ?Carbon properties, a named-argument swap between them) and passed
// all twelve of its tests, because its fixture left both values null. This file closes the same
// gap here, with the fixture the context file asks for: a uuid for userId, a human name for
// displayName, obviously distinct.
//
// tests/Feature, not tests/Unit, for the same reason PlanSummaryTest (Task 11) is: a PlanMember
// with a REAL (non-null) joined_at needs Eloquent's date-cast machinery on both the write and
// the read side. Writing a non-null value to the 'joined_at' cast calls
// HasAttributes::setAttribute() -> fromDateTime() -> getDateFormat() -> getConnection(), and
// reading a raw Carbon instance back through the cast calls HasAttributes::asDateTime() ->
// Illuminate\Support\Facades\Date::instance() - a real Facade call. Both need a resolved
// connection/container, which tests/Unit never boots (tests/Pest.php's single
// uses(TestCase::class)->in('Feature') line names Feature only). Measured: moving this
// fixture under tests/Unit throws "Call to a member function connection() on null" from inside
// vendor/laravel/framework's Model.php, before this file's own assertions ever run.
function planMemberFixtureForView(): PlanMember
{
    $member = new PlanMember;

    $member->forceFill([
        'id' => '0192f0a1-cccc-7000-8000-000000000003',
        'plan_id' => '0192f0a1-dddd-7000-8000-000000000004',
        'user_id' => '0192f0a1-0000-7000-8000-000000000002',
        'role' => MemberRole::Admin->value,
        'status' => MemberStatus::Active->value,
        'joined_at' => Carbon::parse('2026-09-01 09:00:00+00:00'),
    ]);

    return $member;
}

it('maps every field of a PlanMember onto the matching PlanMemberView field', function (): void {
    $member = planMemberFixtureForView();

    $view = PlanMemberView::fromMember($member, 'Grace Hopper');

    // Field-by-field, not a single toEqual() against a second PlanMemberView: a transposition
    // between userId and displayName would still produce "a PlanMemberView with the right five
    // values in the wrong two slots", which a whole-object comparison would only catch if its
    // own expected object used distinct values too - exactly what this fixture's uuid vs. human
    // name does, and what the per-field assertions below each pin independently.
    expect($view->userId)->toBe('0192f0a1-0000-7000-8000-000000000002', 'userId must come from $member->user_id, not from the $displayName argument')
        ->and($view->displayName)->toBe('Grace Hopper', 'displayName must come from the $displayName argument, not from $member->user_id')
        ->and($view->role)->toBe(MemberRole::Admin)
        ->and($view->status)->toBe(MemberStatus::Active)
        ->and($view->joinedAt->toIso8601String())->toBe('2026-09-01T09:00:00+00:00');
});
