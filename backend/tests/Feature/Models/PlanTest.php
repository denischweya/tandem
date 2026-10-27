<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\assertDatabaseCount;

// DeleteAccountTest reaches PlanFactory and GroupFactory, but builds every member and
// activity row through a factory with explicit attributes so that "longest-standing" is
// deterministic - so GroupMemberFactory and PlanActivityFactory, and all four of the Plan and
// Group relations on User, would otherwise ship without ever being executed. That is the same
// gap tests/Feature/Models/UserTest.php exists to close for User.
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

it('refuses to mass-assign any attribute on Plan', function (): void {
    // The Wave 2 shape this exists for: a controller passing $request->validated() into an
    // Application use case which calls Plan::create($validated). That breaks no rule in this
    // codebase - spec §6 and the "controllers touch no Eloquent and no persistence" arch rule
    // both pass, because the controller still touches no Eloquent - and it would make
    // plans.owner_id, plans.status and plan_members.role client-settable. The previous guard
    // was a docblock describing that condition.
    //
    // Loud, not silent: $guarded = ['*'] makes totallyGuarded() true, which is the condition
    // Model::fill() throws on. An empty #[Fillable] allowlist - the obvious spelling - permits
    // everything instead; see Plan's docblock for that measurement and two others.
    expect((new Plan)->totallyGuarded())->toBeTrue();

    foreach (['owner_id', 'status', 'visibility', 'group_id', 'title'] as $column) {
        expect(fn () => (new Plan)->fill([$column => 'anything']))
            ->toThrow(MassAssignmentException::class);
    }
});

it('refuses to mass-assign any attribute on PlanMember', function (): void {
    expect((new PlanMember)->totallyGuarded())->toBeTrue();

    foreach (['role', 'status', 'plan_id', 'user_id'] as $column) {
        expect(fn () => (new PlanMember)->fill([$column => 'anything']))
            ->toThrow(MassAssignmentException::class);
    }
});

it('still lets forceFill and the factories write those columns', function (): void {
    // The cost side of the posture above, asserted so that "forceFill is unaffected" is not
    // taken on trust: every write that exists today goes through forceFill() or a factory, and
    // Illuminate's Factory wraps instantiation in Model::unguarded().
    $plan = Plan::factory()->create(['status' => PlanStatus::Archived->value]);

    expect($plan->status)->toBe(PlanStatus::Archived->value);

    $plan->forceFill(['status' => PlanStatus::Active->value])->save();

    expect($plan->refresh()->status)->toBe(PlanStatus::Active->value);

    $member = PlanMember::factory()->create([
        'plan_id' => $plan->id,
        'user_id' => $plan->owner_id,
        'role' => MemberRole::Owner->value,
    ]);

    expect($member->role)->toBe(MemberRole::Owner->value);
});
