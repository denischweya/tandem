<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Infrastructure\Outbox\OutboxDispatcher;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\OutboxEvent;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanMember;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseEmpty;
use function Pest\Laravel\assertNotSoftDeleted;
use function Pest\Laravel\assertSoftDeleted;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('soft-deletes the account and ends every session', function (): void {
    // An install_id, so the login really creates a devices row - signIn()'s default sends
    // none (the web BFF has no install_id: ADR 0004), and without one the "no device
    // survives" assertion below would pass just as happily against a login that never
    // created a device in the first place.
    [$user, $refresh, $access] = signIn(installId: 'install-abc');

    assertDatabaseCount('devices', 1);

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    assertSoftDeleted('users', ['id' => $user->id]);
    expect(RefreshToken::whereNull('revoked_at')->count())->toBe(0);
    expect($user->refresh()->tokens()->count())->toBe(0);
    assertDatabaseEmpty('devices');

    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
});

it('requires authentication', function (): void {
    deleteJson('/api/v1/me')->assertUnauthorized();
});

it('writes one user.deleted outbox event', function (): void {
    [, , $access] = signIn();

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    expect(OutboxEvent::where('event_type', 'user.deleted')->count())->toBe(1);

    // The handler for this type is a deliberate no-op, but it still has to be registered:
    // OutboxDispatcher leaves an event with no handler pending for ever and records "No
    // handler registered", so without this the event type would silently accumulate
    // unpublishable rows. Dispatching here is the only thing that proves the registration in
    // AppServiceProvider exists and spells the type exactly as the use case writes it -
    // OutboxHandlers::register() overwrites a duplicate silently, so a typo is invisible.
    $summary = app(OutboxDispatcher::class)->dispatchPending();

    expect($summary->published)->toBe(1);
    expect($summary->failed)->toBe(0);
    expect(OutboxEvent::whereNull('published_at')->count())->toBe(0);
});

it('transfers plan ownership to the longest-standing admin', function (): void {
    [$owner, , $access] = signIn();
    $newerAdmin = User::factory()->hasProfile()->create();
    $olderAdmin = User::factory()->hasProfile()->create();
    $plainMember = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 00:00:00');
    member($plan, $newerAdmin, MemberRole::Admin, '2026-03-01 00:00:00');
    member($plan, $olderAdmin, MemberRole::Admin, '2026-02-01 00:00:00');
    member($plan, $plainMember, MemberRole::Member, '2026-01-02 00:00:00');

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // The oldest admin, not the oldest member: §9.3 step 2 prefers an admin even when a plain
    // member has been there longer.
    expect($plan->refresh()->owner_id)->toBe($olderAdmin->id);
    expect($plan->refresh()->status)->toBe(PlanStatus::Active->value);
    expect(PlanMember::where('plan_id', $plan->id)->where('user_id', $olderAdmin->id)->sole()->role)
        ->toBe(MemberRole::Owner->value);

    // The departing owner's own row is demoted as well as marked left. Only (plan_id,
    // user_id) is unique here, so leaving it at 'owner' would give this Plan two rows
    // claiming the role - one left, one active - and any reader that answers "who owns this
    // Plan" from plan_members.role rather than plans.owner_id would see both.
    $departing = PlanMember::where('plan_id', $plan->id)->where('user_id', $owner->id)->sole();

    expect($departing->role)->toBe(MemberRole::Member->value);
    expect($departing->status)->toBe(MemberStatus::Left->value);
});

it('falls back to the longest-standing active member when there is no admin', function (): void {
    [$owner, , $access] = signIn();
    $newer = User::factory()->hasProfile()->create();
    $older = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 00:00:00');
    member($plan, $newer, MemberRole::Member, '2026-03-01 00:00:00');
    member($plan, $older, MemberRole::Member, '2026-02-01 00:00:00');

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    expect($plan->refresh()->owner_id)->toBe($older->id);
});

it('skips members who are not active when choosing a successor', function (): void {
    [$owner, , $access] = signIn();
    $invited = User::factory()->hasProfile()->create();
    $active = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 00:00:00');
    PlanMember::create([
        'plan_id' => $plan->id, 'user_id' => $invited->id,
        'role' => MemberRole::Admin->value, 'status' => MemberStatus::Invited->value, 'joined_at' => null,
    ]);
    member($plan, $active, MemberRole::Member, '2026-05-01 00:00:00');

    // An invited admin has never accepted. Handing them a Plan they have not joined would
    // give a Plan an owner who cannot see it.
    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    expect($plan->refresh()->owner_id)->toBe($active->id);
});

it('archives a plan whose only member was the departing owner', function (): void {
    [$owner, , $access] = signIn();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 00:00:00');

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    expect($plan->refresh()->status)->toBe(PlanStatus::Archived->value);
    expect($plan->refresh()->archived_at)->not->toBeNull();
    // owner_id stays, because plans.owner_id is NOT NULL with RESTRICT. The day-30 job in
    // Task 9 is what finally removes this Plan.
    expect($plan->refresh()->owner_id)->toBe($owner->id);
});

it('resolves every plan it owns in one pass, not just the first', function (): void {
    [$owner, , $access] = signIn();
    $successor = User::factory()->hasProfile()->create();

    $shared = Plan::factory()->create(['owner_id' => $owner->id]);
    member($shared, $owner, MemberRole::Owner, '2026-01-01 00:00:00');
    member($shared, $successor, MemberRole::Member, '2026-02-01 00:00:00');

    $firstSolo = Plan::factory()->create(['owner_id' => $owner->id]);
    member($firstSolo, $owner, MemberRole::Owner, '2026-01-01 00:00:00');

    $secondSolo = Plan::factory()->create(['owner_id' => $owner->id]);
    member($secondSolo, $owner, MemberRole::Owner, '2026-01-01 00:00:00');

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // The transfer branch and the archive branch in one pass, which is the only thing that
    // catches a `break` written where the archive branch's `continue` belongs: that would
    // leave every Plan after the first archived one sitting on an owner who no longer exists,
    // and stranding a Plan is the worst thing this use case can do.
    //
    // Two sole-member Plans rather than one, deliberately. Plan::query() carries no ORDER BY,
    // so the row order belongs to Postgres, and with only one archiving Plan a `break` would
    // go unnoticed in every order that happened to visit it last. With two, there is no order
    // in which breaking out after the first archive strands nothing.
    expect($shared->refresh()->owner_id)->toBe($successor->id);
    expect($shared->refresh()->status)->toBe(PlanStatus::Active->value);

    foreach ([$firstSolo, $secondSolo] as $solo) {
        expect($solo->refresh()->status)->toBe(PlanStatus::Archived->value);
        expect($solo->refresh()->archived_at)->not->toBeNull();
        expect($solo->refresh()->owner_id)->toBe($owner->id);
    }
});

it('resolves every group it owns in one pass, not just the first', function (): void {
    [$owner, , $access] = signIn();
    $successor = User::factory()->hasProfile()->create();

    $shared = Group::factory()->create(['owner_id' => $owner->id]);
    GroupMember::create(['group_id' => $shared->id, 'user_id' => $owner->id, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
    GroupMember::create(['group_id' => $shared->id, 'user_id' => $successor->id, 'created_at' => '2026-02-01', 'updated_at' => '2026-02-01']);

    // Two sole-member Groups, for the reason the Plan test above records: it makes a `break`
    // substituted for the delete branch's `continue` fail in every possible row order.
    foreach (['2026-01-01', '2026-01-02'] as $created) {
        $solo = Group::factory()->create(['owner_id' => $owner->id]);
        GroupMember::create(['group_id' => $solo->id, 'user_id' => $owner->id, 'created_at' => $created, 'updated_at' => $created]);
    }

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    assertDatabaseCount('groups', 1);
    expect($shared->refresh()->owner_id)->toBe($successor->id);
    // Only the successor's membership is left: the departing user's three rows went with them
    // and the two deleted Groups took their own with them by cascade.
    assertDatabaseCount('group_members', 1);
});

it('marks the departing user as left in every plan they were a member of', function (): void {
    [$user, , $access] = signIn();
    $other = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $other->id]);
    member($plan, $other, MemberRole::Owner, '2026-01-01 00:00:00');
    member($plan, $user, MemberRole::Member, '2026-02-01 00:00:00');

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    expect(PlanMember::where('user_id', $user->id)->sole()->status)->toBe(MemberStatus::Left->value);
    expect($plan->refresh()->owner_id)->toBe($other->id);
});

it('transfers group ownership to the longest-standing remaining member', function (): void {
    [$owner, , $access] = signIn();
    $newer = User::factory()->hasProfile()->create();
    $older = User::factory()->hasProfile()->create();

    $group = Group::factory()->create(['owner_id' => $owner->id]);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $owner->id, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $newer->id, 'created_at' => '2026-03-01', 'updated_at' => '2026-03-01']);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $older->id, 'created_at' => '2026-02-01', 'updated_at' => '2026-02-01']);

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    expect($group->refresh()->owner_id)->toBe($older->id);
    // The departing user's own membership row goes; the two that remain do not.
    assertDatabaseCount('group_members', 2);
});

it('deletes a sole-member group and leaves its plans intact with group_id cleared', function (): void {
    [$owner, , $access] = signIn();

    $group = Group::factory()->create(['owner_id' => $owner->id]);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);

    $survivor = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $survivor->id, 'group_id' => $group->id]);
    member($plan, $survivor, MemberRole::Owner, '2026-01-01 00:00:00');

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // plans.group_id is nullable with SET NULL, which is exactly what makes deleting the
    // Group safe (§9.3 step 3). The Plan survives with its group association cleared.
    assertDatabaseEmpty('groups');
    expect($plan->refresh()->group_id)->toBeNull();
    expect($plan->refresh()->status)->toBe(PlanStatus::Active->value);
});

it('anonymizes the departing user in the activity feed instead of deleting it', function (): void {
    [$user, , $access] = signIn();
    $other = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $other->id]);
    member($plan, $other, MemberRole::Owner, '2026-01-01 00:00:00');
    member($plan, $user, MemberRole::Member, '2026-02-01 00:00:00');

    $mine = PlanActivity::create([
        'plan_id' => $plan->id, 'actor_id' => $user->id,
        'activity_type' => 'plan.created', 'metadata' => [], 'created_at' => now(),
    ]);
    $theirs = PlanActivity::create([
        'plan_id' => $plan->id, 'actor_id' => $other->id,
        'activity_type' => 'member.joined', 'metadata' => [], 'created_at' => now(),
    ]);

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // §51 requires activity records be immutable; the feed must stay intelligible to the
    // members who remain, so the actor reference is nulled rather than the row removed.
    assertDatabaseCount('plan_activity', 2);
    expect($mine->refresh()->actor_id)->toBeNull();
    expect($theirs->refresh()->actor_id)->toBe($other->id);
});

it('does nothing at all if any step fails', function (): void {
    [$user, , $access] = signIn();

    // A Group whose owner_id points at a user row that will not be deletable is not
    // reproducible here; instead, assert the transaction boundary directly by forcing the
    // outbox write to fail.
    //
    // Not Pest\Laravel\mock(OutboxWriter::class) - and not $this->mock() either, which is a
    // PHPStan error inside a Pest closure. OutboxWriter is `final readonly`, so Mockery
    // refuses it outright: "The class \App\Infrastructure\Outbox\OutboxWriter is marked
    // final and its methods cannot be replaced" (measured against this project's installed
    // Mockery). Throwing from the model event the writer's single INSERT fires is the same
    // injection one layer in, and it fails at exactly the point in the transaction a real
    // outbox failure would. The listener is registered on this test's own event dispatcher,
    // which is rebuilt with the application for every test, so it cannot leak.
    OutboxEvent::saving(function (): void {
        throw new RuntimeException('outbox is down');
    });

    withToken($access)->deleteJson('/api/v1/me')->assertStatus(500);

    // Steps 1-5 and the event are one transaction (§9.3). A half-deleted account - sessions
    // revoked, user row intact - is the worst outcome available.
    assertNotSoftDeleted('users', ['id' => $user->id]);
    expect(RefreshToken::whereNull('revoked_at')->count())->toBe(1);
});

it('does not hand a plan to a soft-deleted member', function (): void {
    // Two members of one Plan both calling DELETE /api/v1/me is not hypothetical: it is one
    // user deleting their account while the other does the same, and the Plan branch here
    // reads plan_members with no regard for whether the candidate's account still exists. The
    // result committed a live, `active`, multi-member Plan whose owner_id names a soft-deleted
    // row - which the day-30 purge then had to deal with, and used to deal with by destroying
    // the Plan (see PurgeDeletedUsersCommand's status predicate).
    [$owner, , $access] = signIn();
    $departed = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 00:00:00');
    member($plan, $departed, MemberRole::Admin, '2026-02-01 00:00:00');

    // The other member has already deleted their account. Their plan_members row says
    // active - DeleteAccount marks it `left` for its OWN user, which this one is not.
    $departed->delete();

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // No eligible successor, so branch 3 applies: archive, and leave owner_id where the
    // RESTRICT needs it.
    expect($plan->refresh()->owner_id)->toBe($owner->id);
    expect($plan->refresh()->status)->toBe(PlanStatus::Archived->value);
});

it('does not hand a plan to a suspended member', function (): void {
    // A suspended account cannot sign in at all (Login::execute() refuses it), so a Plan
    // transferred to one has an owner who can never administer it - the same defect as handing
    // it to an invited member, which this branch already guards against.
    [$owner, , $access] = signIn();
    $suspended = User::factory()->hasProfile()->create(['suspended_at' => now()]);
    $usable = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 00:00:00');
    member($plan, $suspended, MemberRole::Admin, '2026-02-01 00:00:00');
    member($plan, $usable, MemberRole::Member, '2026-05-01 00:00:00');

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // The suspended admin would otherwise win on both counts - admin, and longer-standing.
    expect($plan->refresh()->owner_id)->toBe($usable->id);
});

it('does not hand a group to a soft-deleted member', function (): void {
    // Worse here than for Plans, because groups.owner_id has no archive branch to fall back
    // on: a Group left pointing at a soft-deleted user makes that user's day-30 forceDelete()
    // hit RESTRICT every night for ever, with the purge reporting a failure it cannot resolve.
    [$owner, , $access] = signIn();
    $departed = User::factory()->hasProfile()->create();

    $group = Group::factory()->create(['owner_id' => $owner->id]);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $owner->id, 'created_at' => '2026-01-01 00:00:00']);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $departed->id, 'created_at' => '2026-02-01 00:00:00']);

    $departed->delete();

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // No eligible member left, so the sole-member branch applies and the Group goes. Safe
    // because plans.group_id is nullable with SET NULL.
    expect(Group::query()->whereKey($group->id)->exists())->toBeFalse();
});

it('does not hand a group to a suspended member', function (): void {
    [$owner, , $access] = signIn();
    $suspended = User::factory()->hasProfile()->create(['suspended_at' => now()]);
    $usable = User::factory()->hasProfile()->create();

    $group = Group::factory()->create(['owner_id' => $owner->id]);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $owner->id, 'created_at' => '2026-01-01 00:00:00']);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $suspended->id, 'created_at' => '2026-02-01 00:00:00']);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $usable->id, 'created_at' => '2026-05-01 00:00:00']);

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    expect($group->refresh()->owner_id)->toBe($usable->id);
});

it('leaves no purgeable user owning a plan or a group, which is what makes the purge quiet', function (): void {
    // The end-to-end statement of the two branches above, through the command that pays for
    // getting them wrong. Both candidates are unusable, so both resources resolve without a
    // transfer, and thirty days later the purge finds nothing it has to refuse.
    [$owner, , $access] = signIn();
    $departed = User::factory()->hasProfile()->create();
    $departed->delete();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 00:00:00');
    member($plan, $departed, MemberRole::Admin, '2026-02-01 00:00:00');

    $group = Group::factory()->create(['owner_id' => $owner->id]);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $owner->id, 'created_at' => '2026-01-01 00:00:00']);
    GroupMember::create(['group_id' => $group->id, 'user_id' => $departed->id, 'created_at' => '2026-02-01 00:00:00']);

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    DB::table('users')->where('id', $owner->id)->update(['deleted_at' => now()->subDays(31)]);

    runArtisan('users:purge-deleted')
        ->expectsOutputToContain('"failed":0')
        ->assertSuccessful();

    expect(User::withTrashed()->whereKey($owner->id)->exists())->toBeFalse();
    expect(Plan::query()->whereKey($plan->id)->exists())->toBeFalse();
    expect(Group::query()->whereKey($group->id)->exists())->toBeFalse();
});
