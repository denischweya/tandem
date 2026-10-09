<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\assertDatabaseCount;

// DeleteAccountTest reaches PlanFactory and GroupFactory, but builds every member and
// activity row with an explicit create() so that "longest-standing" is deterministic - so
// PlanMemberFactory, GroupMemberFactory and PlanActivityFactory, and all four of the
// relations this task adds to User, would otherwise ship without ever being executed. That
// is the same gap tests/Feature/Models/UserTest.php exists to close for User.
uses(RefreshDatabase::class);

it('creates a persistable row from every factory this task adds', function (): void {
    $plan = Plan::factory()->create();
    PlanMember::factory()->create(['plan_id' => $plan->id]);
    PlanActivity::factory()->create(['plan_id' => $plan->id]);

    $group = Group::factory()->create();
    GroupMember::factory()->create(['group_id' => $group->id]);

    // One each, which also proves that overriding an id attribute leaves the parent factory
    // in the definition unresolved rather than creating a second Plan or Group alongside it.
    assertDatabaseCount('plans', 1);
    assertDatabaseCount('plan_members', 1);
    assertDatabaseCount('plan_activity', 1);
    assertDatabaseCount('groups', 1);
    assertDatabaseCount('group_members', 1);
});

it('separates owning a plan or group from being a member of one', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    PlanMember::factory()->create(['plan_id' => $plan->id, 'user_id' => $member->id]);

    $group = Group::factory()->create(['owner_id' => $owner->id]);
    GroupMember::factory()->create(['group_id' => $group->id, 'user_id' => $member->id]);

    expect($owner->plans()->pluck('id')->all())->toBe([$plan->id]);
    expect($owner->groups()->pluck('id')->all())->toBe([$group->id]);

    // Owning is not membership, and nothing creates the owner's membership row implicitly.
    // That asymmetry is why §9.3 needs an archive branch at all: a Plan can have an owner and
    // no members, in which case there is nobody to transfer it to.
    expect($owner->planMemberships()->count())->toBe(0);
    expect($owner->groupMemberships()->count())->toBe(0);

    expect($member->plans()->count())->toBe(0);
    expect($member->groups()->count())->toBe(0);
    expect($member->planMemberships()->pluck('plan_id')->all())->toBe([$plan->id]);
    expect($member->groupMemberships()->pluck('group_id')->all())->toBe([$group->id]);

    expect($plan->members()->pluck('user_id')->all())->toBe([$member->id]);
    expect($group->members()->pluck('user_id')->all())->toBe([$member->id]);
});
