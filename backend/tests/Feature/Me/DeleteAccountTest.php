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
use App\Models\PlanInvite;
use App\Models\PlanMember;
use App\Models\PlanMessage;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseEmpty;
use function Pest\Laravel\assertNotSoftDeleted;
use function Pest\Laravel\assertSoftDeleted;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
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
    // A typo in the event type would leave this row unpublishable for ever instead.
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
    PlanMember::factory()->create([
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

it('anonymizes the departing user’s chat messages instead of deleting them', function (): void {
    // Step 4's other half (ADR 0012). The chat design document §3 argues plan_messages.user_id
    // from plan_activity.actor_id's anonymisation - "a feed that loses rows as users leave is a
    // worse feed" - and the conversation the remaining members are reading is the same case.
    // Nothing anonymised plan_messages before this: the column shipped NOT NULL, so there was
    // nowhere for this transaction to write the anonymised state.
    [$user, , $access] = signIn();
    $other = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $other->id]);
    member($plan, $other, MemberRole::Owner, '2026-01-01 00:00:00');
    member($plan, $user, MemberRole::Member, '2026-02-01 00:00:00');

    // Field by field through `new PlanMessage`, not a factory: PlanMessage is $guarded = ['*']
    // and this feature carries no PlanMessageFactory - the same construction every test under
    // tests/Feature/Chat/ uses.
    $mine = new PlanMessage;
    $mine->id = (string) Str::uuid7();
    $mine->plan_id = $plan->id;
    $mine->user_id = $user->id;
    $mine->body = 'see you there';
    $mine->save();

    $theirs = new PlanMessage;
    $theirs->id = (string) Str::uuid7();
    $theirs->plan_id = $plan->id;
    $theirs->user_id = $other->id;
    $theirs->body = 'bring cash';
    $theirs->save();

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // Two rows still, exactly as the activity-feed case above asserts for plan_activity: the
    // author reference is nulled, not the message removed.
    assertDatabaseCount('plan_messages', 2);
    expect($mine->refresh()->user_id)->toBeNull();
    expect($mine->refresh()->body)->toBe('see you there');
    expect($theirs->refresh()->user_id)->toBe($other->id);
});

it('does nothing at all if any step fails', function (): void {
    // A pinned address rather than the factory's random one, so the §14.1 assertion at the
    // bottom of this test has a literal to fail against.
    [$user, , $access] = signIn(userWithPassword(attributes: ['email' => 'ada@example.test']));

    // One plan_invites row that BOTH §14.1 clauses match - sent by this user, addressed to this
    // user, neither accepted nor revoked. Both clauses are writes inside the same transaction as
    // steps 1-5, so both roll back with it; a clause written outside DB::transaction() would
    // commit on its own and this test is the only thing in the suite that would notice.
    $plan = ownedPlan($user);
    $invite = PlanInvite::factory()->create([
        'plan_id' => $plan->id, 'invited_by' => $user->id, 'email' => 'ada@example.test',
    ]);

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

    // §14.1's two clauses are inside that same boundary. An invite revoked or anonymised by a
    // deletion that did not happen is worse than one that was never touched: the Plan's owner
    // can no longer tell whether the link they sent is live, and the row is unrecoverable.
    expect($invite->refresh()->email)->toBe('ada@example.test');
    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('revoked_at')->count())
        ->toBe(1);
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

it('revokes the outstanding invites the departing user sent, and nothing else', function (): void {
    // §14.1 clause 1. An invite is an exercise of the inviter's authority over a Plan; when the
    // account ends, that authority ends with it, and the one person who could have revoked the
    // invite no longer exists.
    [$user, , $access] = signIn();
    $stranger = User::factory()->hasProfile()->create();

    $ownPlan = ownedPlan($user);
    $othersPlan = ownedPlan($stranger);
    // An admin on somebody else's Plan: clause 1 is not Plan-scoped, and an invite sent from
    // here is still this user's exercise of authority.
    member($othersPlan, $user, MemberRole::Admin, '2026-02-01 00:00:00');

    $sent = PlanInvite::factory()->create(['plan_id' => $ownPlan->id, 'invited_by' => $user->id]);
    // email IS NULL: §10.5's link-only channel. Clause 1 never reads email, so this row is
    // revoked on exactly the same terms as an emailed one - which is the half of the behaviour
    // a predicate written on `email` instead of `invited_by` would silently lose.
    $sentLinkOnly = PlanInvite::factory()->linkOnly()
        ->create(['plan_id' => $ownPlan->id, 'invited_by' => $user->id]);
    $sentElsewhere = PlanInvite::factory()
        ->create(['plan_id' => $othersPlan->id, 'invited_by' => $user->id]);
    $sentByStranger = PlanInvite::factory()
        ->create(['plan_id' => $othersPlan->id, 'invited_by' => $stranger->id]);
    // Accepted: somebody already joined through it, so there is no live credential to end and
    // the row is part of the Plan's membership history (blueprint §127 step 6).
    $alreadyAccepted = PlanInvite::factory()->accepted()
        ->create(['plan_id' => $ownPlan->id, 'invited_by' => $user->id]);
    $alreadyRevoked = PlanInvite::factory()->revoked()->create([
        'plan_id' => $ownPlan->id,
        'invited_by' => $user->id,
        'revoked_at' => '2026-09-01 08:00:00',
    ]);

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // A map rather than six separate expectations: a predicate that is too wide and a predicate
    // that is too narrow then fail in the same assertion, by name, with a diff that says which
    // row moved. Six independent toBeNull()/not->toBeNull() calls report the first failure only,
    // and report it as an exported Carbon object.
    expect([
        'sent by them' => $sent->refresh()->revoked_at !== null,
        'sent by them, link-only' => $sentLinkOnly->refresh()->revoked_at !== null,
        'sent by them on a Plan they do not own' => $sentElsewhere->refresh()->revoked_at !== null,
        'sent by somebody else' => $sentByStranger->refresh()->revoked_at !== null,
        'sent by them and already accepted' => $alreadyAccepted->refresh()->revoked_at !== null,
    ])->toBe([
        'sent by them' => true,
        'sent by them, link-only' => true,
        'sent by them on a Plan they do not own' => true,
        'sent by somebody else' => false,
        'sent by them and already accepted' => false,
    ]);

    // The accepted row keeps its acceptance, which is the thing that made it exempt.
    expect($alreadyAccepted->refresh()->accepted_at)->not->toBeNull();

    // Not re-stamped. Clause 1's `revoked_at IS NULL` is what keeps an invite revoked weeks ago
    // carrying its own timestamp: re-stamping it would rewrite the trail plan_invites'
    // nullOnDelete choices exist to protect. ?-> because the datetime cast makes this
    // Carbon|null and PHPStan runs at level 8.
    expect($alreadyRevoked->refresh()->revoked_at?->toDateTimeString())->toBe('2026-09-01 08:00:00');
});

it('makes an invite issued by a deleted account unacceptable immediately, not in fourteen days', function (): void {
    // The security statement §14.1 exists to make, through real routes rather than through a
    // column assertion: without clause 1 the credential below stays acceptable for the rest of
    // its 14 days, and the Plan it opens has by then been handed to an admin who never approved
    // it.
    [$inviter, , $access] = signIn();
    $successor = User::factory()->hasProfile()->create();
    $outsider = userWithPassword(attributes: ['email' => 'outsider@example.test']);

    $plan = ownedPlan($inviter);
    // A second active member, so ownership TRANSFERS rather than the Plan being archived. That
    // is §14.1's scenario exactly - "a Plan whose ownership has since transferred to someone who
    // never approved it" - and it is also what stops the two 404s below passing because the Plan
    // itself went read-only.
    member($plan, $successor, MemberRole::Admin, '2026-02-01 00:00:00');

    [$invite, $token] = invited($plan, $inviter, MemberRole::Member, 'outsider@example.test');

    // The control. Without it, both 404s below would pass just as happily against an invite that
    // was never acceptable in the first place.
    getJson("/api/v1/invites/{$token}")
        ->assertOk()
        ->assertJsonPath('data.plan_id', $plan->id);

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    // Auth::forgetGuards(). The guard caches the resolved user for the rest of the request
    // lifecycle, and the next authenticated request below belongs to a different account; without
    // this, "the outsider cannot join" could be passing because the outsider was never the one
    // asking.
    forgetAuthenticatedUser();

    expect($plan->refresh()->owner_id)->toBe($successor->id);
    expect($plan->refresh()->status)->toBe(PlanStatus::Active->value);
    expect($invite->refresh()->revoked_at)->not->toBeNull();

    // The unauthenticated preview, which is the only thing a holder of this link can reach
    // without an account at all.
    getJson("/api/v1/invites/{$token}")->assertNotFound();

    [, , $outsiderAccess] = signIn($outsider);

    $join = withToken($outsiderAccess)
        ->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertNotFound();

    // BYTE-identical, not merely equal JSON: a router miss, a ModelNotFoundException or
    // Laravel's own handler would render the same message through prepareJsonResponse() with
    // JSON_PRETTY_PRINT, and assertExactJson would pass for both. This body can only have come
    // from the InviteNotUsable render() closure, which is the refusal §10.7 specifies.
    expect((string) $join->getContent())
        ->toBe('{"message":"This invite link is no longer valid. Ask whoever invited you for a new one."}');

    // And no membership row was created on the way to that 404.
    expect(PlanMember::where('plan_id', $plan->id)->where('user_id', $outsider->id)->exists())
        ->toBeFalse();
});

it('nulls the email of unaccepted invites addressed to the departing user', function (): void {
    // §14.1 clause 2, and blueprint §127 step 3: an unaccepted invite row carrying a deleted
    // user's address is personal information with no trail value, because nobody joined through
    // it. Every row below was sent by somebody else, so clause 1 cannot be what moves them.
    [$user, , $access] = signIn(userWithPassword(attributes: ['email' => 'ada@example.test']));
    $stranger = User::factory()->hasProfile()->create();

    $plan = ownedPlan($stranger);
    member($plan, $user, MemberRole::Member, '2026-02-01 00:00:00');

    $addressed = PlanInvite::factory()->create([
        'plan_id' => $plan->id, 'invited_by' => $stranger->id, 'email' => 'ada@example.test',
    ]);
    // Revoked but never accepted: clause 2 carries no revoked_at predicate, unlike clause 1,
    // because revocation is not erasure and this row still carries the address.
    $addressedAndRevoked = PlanInvite::factory()->revoked()->create([
        'plan_id' => $plan->id, 'invited_by' => $stranger->id, 'email' => 'ada@example.test',
    ]);
    // Accepted BY this user: accepted_by makes it part of the Plan's membership history, which
    // blueprint §127 step 6 preserves, so the address stays. This is the row persistence and
    // resources both argue PlanInviteView::$invitedByName's nullability from - it survives here
    // to lose invited_by to the day-30 purge instead.
    $acceptedByThem = PlanInvite::factory()->accepted()->create([
        'plan_id' => $plan->id, 'invited_by' => $stranger->id,
        'email' => 'ada@example.test', 'accepted_by' => $user->id,
    ]);
    $someoneElse = PlanInvite::factory()->create([
        'plan_id' => $plan->id, 'invited_by' => $stranger->id, 'email' => 'grace@example.test',
    ]);

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    expect([
        'unaccepted, addressed to them' => $addressed->refresh()->email,
        'unaccepted and revoked, addressed to them' => $addressedAndRevoked->refresh()->email,
        'accepted by them' => $acceptedByThem->refresh()->email,
        'addressed to somebody else' => $someoneElse->refresh()->email,
    ])->toBe([
        'unaccepted, addressed to them' => null,
        'unaccepted and revoked, addressed to them' => null,
        'accepted by them' => 'ada@example.test',
        'addressed to somebody else' => 'grace@example.test',
    ]);

    // Clause 2 nulls the address and nothing else, so this invite is now link-only and still
    // live. That widens nothing: §10.6 already does not require accepted_by to match email, so
    // the address was addressing and never authorization, and the credential is the 64-hex token
    // either way. Clause 1 does not reach this row because invited_by is the stranger.
    //
    // Counted off the raw column rather than asserted as `->revoked_at` toBeNull(): a null
    // assertion against the datetime cast reports an exported Carbon object on failure, and
    // "Failed asserting that 0 is identical to 1" is the message a break step can be written
    // against.
    expect(DB::table('plan_invites')->where('id', $addressed->id)->whereNull('revoked_at')->count())
        ->toBe(1);
});

it('revokes and anonymises the one row where the departing user invited their own address', function (): void {
    // The overlap. Both clauses match this row, and the order between them is immaterial -
    // clause 1's predicate never reads email and clause 2's never reads revoked_at, so whichever
    // runs first leaves the other's predicate intact. Asserted rather than reasoned about,
    // because "the order is immaterial" is exactly the claim this use case's own comments record
    // having got wrong once.
    [$user, , $access] = signIn(userWithPassword(attributes: ['email' => 'ada@example.test']));

    $plan = ownedPlan($user);
    $both = PlanInvite::factory()->create([
        'plan_id' => $plan->id, 'invited_by' => $user->id, 'email' => 'ada@example.test',
    ]);

    withToken($access)->deleteJson('/api/v1/me')->assertNoContent();

    expect($both->refresh()->email)->toBeNull();
    expect(DB::table('plan_invites')->where('id', $both->id)->whereNotNull('revoked_at')->count())
        ->toBe(1);
});
