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

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseEmpty;
use function Pest\Laravel\assertNotSoftDeleted;
use function Pest\Laravel\assertSoftDeleted;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

/** A member row, created with an explicit joined_at so "longest-standing" is deterministic. */
function member(Plan $plan, User $user, MemberRole $role, string $joinedAt): PlanMember
{
    return PlanMember::create([
        'plan_id' => $plan->id,
        'user_id' => $user->id,
        'role' => $role->value,
        'status' => MemberStatus::Active->value,
        'joined_at' => $joinedAt,
    ]);
}

/**
 * Signs in sending an install_id, which is the only thing that creates a devices row.
 *
 * signIn() in tests/Pest.php deliberately sends none - the web BFF has no install_id
 * concept (ADR 0004) - so it cannot be reused by the one test here that must see a device
 * exist before the deletion in order for "no device survives" to mean anything.
 *
 * The two is_string() checks are the same real narrowing signIn() does, and for the same
 * reason: TestResponse::json() is declared mixed, and both values are fed straight into
 * withToken() and a refresh request.
 *
 * @return array{string, string} [access token, refresh token]
 */
function signInOnDevice(User $user, string $password = 'correct horse battery staple'): array
{
    $data = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => $password,
        'install_id' => 'install-abc',
        'platform' => 'ios',
    ])->assertOk()->json('data');

    $access = is_array($data) ? $data['access_token'] ?? null : null;
    $refresh = is_array($data) ? $data['refresh_token'] ?? null : null;

    if (! is_string($access) || ! is_string($refresh)) {
        throw new RuntimeException('POST /api/v1/auth/login did not return both tokens as strings.');
    }

    return [$access, $refresh];
}

it('soft-deletes the account and ends every session', function (): void {
    $user = userWithPassword();

    [$access, $refresh] = signInOnDevice($user);

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
