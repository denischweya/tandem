<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Models\Group;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

// No `use RuntimeException;` here: this file has no namespace, and RuntimeException is
// already global, so importing it triggers PHP's "The use statement with non-compound
// name 'RuntimeException' has no effect" warning - a real PHPUnit-counted warning, not a
// cosmetic one. Reference it unqualified below instead.

use function Pest\Laravel\artisan;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertModelMissing;
use function Pest\Laravel\assertNotSoftDeleted;
use function Pest\Laravel\assertSoftDeleted;

uses(RefreshDatabase::class);

function softDeletedDaysAgo(int $days): User
{
    $user = User::factory()->hasProfile()->create();
    $user->delete();
    DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()->subDays($days)]);

    return $user;
}

/**
 * Pest\Laravel\artisan() is typed `PendingCommand|int` because the real method it
 * delegates to - Illuminate\Foundation\Testing\Concerns\InteractsWithConsole::artisan() -
 * genuinely returns `int` when $mockConsoleOutput is false. This suite never disables
 * console mocking (nothing here calls withoutMockingConsoleOutput()), so the
 * PendingCommand branch is the only one ever taken; this narrows the union with a real,
 * checked instanceof rather than an inline @var overriding PHPStan's inferred type.
 *
 * @param  array<string, mixed>  $parameters
 */
function runArtisan(string $command, array $parameters = []): PendingCommand
{
    $pending = artisan($command, $parameters);

    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('Expected artisan() to return a PendingCommand - console output mocking must be disabled.');
    }

    return $pending;
}

it('leaves a user inside the grace window alone', function (): void {
    $user = softDeletedDaysAgo(29);

    runArtisan('users:purge-deleted')->assertSuccessful();

    assertSoftDeleted('users', ['id' => $user->id]);
});

it('hard-deletes a user past the grace window', function (): void {
    $user = softDeletedDaysAgo(31);

    runArtisan('users:purge-deleted')->assertSuccessful();

    assertModelMissing($user);
});

it('never touches a live user', function (): void {
    $user = User::factory()->hasProfile()->create();

    runArtisan('users:purge-deleted')->assertSuccessful();

    assertNotSoftDeleted($user);
});

it('hard-deletes the archived plan the departing user still solely owned', function (): void {
    $user = softDeletedDaysAgo(31);
    $plan = Plan::factory()->create(['owner_id' => $user->id, 'status' => 'archived', 'archived_at' => now()->subDays(31)]);
    PlanMember::create([
        'plan_id' => $plan->id, 'user_id' => $user->id,
        'role' => MemberRole::Owner->value, 'status' => MemberStatus::Left->value, 'joined_at' => now()->subDays(60),
    ]);
    PlanActivity::create([
        'plan_id' => $plan->id, 'actor_id' => null,
        'activity_type' => 'plan.created', 'metadata' => [], 'created_at' => now()->subDays(60),
    ]);

    runArtisan('users:purge-deleted')->assertSuccessful();

    // plans.owner_id is NOT NULL with RESTRICT, so the user cannot be removed while this Plan
    // exists. §9.3 accepts the consequence rather than making owner_id nullable, which would
    // destroy the invariant that a Plan always has a resolvable owner.
    assertModelMissing($plan);
    assertModelMissing($user);
    // plan_members, plan_invites and plan_activity all CASCADE off plans - one statement, not
    // a multi-table teardown. Confirmed directly against the migration, not just this count.
    assertDatabaseCount('plan_activity', 0);
    assertDatabaseCount('plan_members', 0);
});

it('leaves a plan whose ownership was transferred away untouched', function (): void {
    $departed = softDeletedDaysAgo(31);
    $survivor = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $survivor->id]);
    PlanMember::create([
        'plan_id' => $plan->id, 'user_id' => $survivor->id,
        'role' => MemberRole::Owner->value, 'status' => MemberStatus::Active->value, 'joined_at' => now()->subDays(10),
    ]);

    runArtisan('users:purge-deleted')->assertSuccessful();

    assertModelMissing($departed);
    assertDatabaseHas('plans', ['id' => $plan->id]);
});

it('respects an overridden grace window', function (): void {
    $user = softDeletedDaysAgo(5);

    runArtisan('users:purge-deleted', ['--grace-days' => 1])->assertSuccessful();

    assertModelMissing($user);
});

it('reports how many users it purged', function (): void {
    softDeletedDaysAgo(31);
    softDeletedDaysAgo(40);
    softDeletedDaysAgo(5);

    runArtisan('users:purge-deleted')
        ->expectsOutputToContain('"purged":2')
        ->assertSuccessful();
});

it('purges a user soft-deleted exactly GRACE_DAYS ago, not just strictly more', function (): void {
    // A frozen clock, not now()->subDays(30): the command's own cutoff is computed from
    // Clock::now(), so the only way to pin the exact boundary - rather than a boundary plus
    // however many milliseconds elapsed between seeding and running the command - is to
    // control both sides from the same frozen instant.
    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($instant));

    $user = User::factory()->hasProfile()->create();
    $user->delete();
    DB::table('users')->where('id', $user->id)->update(['deleted_at' => $instant->modify('-30 days')]);

    runArtisan('users:purge-deleted')->assertSuccessful();

    // The command compares deleted_at <= cutoff, so a user soft-deleted exactly 30 days ago
    // - to the microsecond - is purged, not kept. "Hard-delete after a 30-day grace window"
    // reads as "once those 30 days have elapsed", and <= is the inclusive reading of
    // "elapsed": the window is over, not still running. The brief's 29/31-day tests
    // straddle this boundary but never pin it; this does.
    assertModelMissing($user);
});

it('relies on account deletion having already resolved group ownership, not on purging it', function (): void {
    $user = softDeletedDaysAgo(31);
    // DeleteAccount::resolveGroupOwnership() (app/Application/Users/DeleteAccount.php) is the
    // only path that produces a soft-deleted user today, and it always empties groups.owner_id
    // for the departing user before step 5 soft-deletes them - by transfer, or by deleting a
    // sole-member Group. This seeds the state that path guarantees never occurs, to pin what
    // this command does if that guarantee is ever broken (e.g. a future bug, or a Group
    // endpoint shipped without updating DeleteAccount - see the plan_tables migration's own
    // warning about exactly that).
    $group = Group::factory()->create(['owner_id' => $user->id]);

    // groups.owner_id is NOT NULL with RESTRICT, the same mechanism Step 5 of this task
    // proves for plans.owner_id. This command does not duplicate DeleteAccount's
    // ownership-transfer logic for Groups - doing so here would let a broken invariant
    // silently "self-heal" on a different schedule than DeleteAccount's, with no evidence
    // anything was ever wrong. Failing loudly is the deliberate choice, but "loudly" now
    // means the run completes, reports the failure, and exits non-successfully - not an
    // uncaught exception. See "continues purging healthy users..." below for why a thrown
    // exception would be the wrong shape: it would take every other purgeable user down
    // with it too.
    runArtisan('users:purge-deleted')
        ->expectsOutputToContain('"purged":0,"failed":1')
        ->assertFailed();

    // The transaction rolled back: nothing was purged, and the Group is untouched.
    assertSoftDeleted('users', ['id' => $user->id]);
    assertDatabaseHas('groups', ['id' => $group->id, 'owner_id' => $user->id]);
});

it('continues purging healthy users after one violates the group invariant, and fails the run', function (): void {
    // Older deleted_at first: the command orders by deleted_at ascending, so this
    // deterministically puts $broken ahead of $healthy in the loop - the same position a
    // systematically failing row would occupy in every future run. Without that ordering
    // this test would be at the mercy of whatever order Postgres happens to return today,
    // which is exactly the nondeterminism that makes the underlying bug dangerous rather
    // than merely cosmetic (see the class doc).
    $broken = softDeletedDaysAgo(40);
    Group::factory()->create(['owner_id' => $broken->id]);

    $healthy = softDeletedDaysAgo(31);

    runArtisan('users:purge-deleted')
        ->expectsOutputToContain('"purged":1,"failed":1')
        ->assertFailed();

    // The one invariant violation did not take the healthy user down with it.
    assertModelMissing($healthy);
    // ...and the broken one is exactly as it was: still queued for the next run, not
    // silently dropped or half-purged.
    assertSoftDeleted('users', ['id' => $broken->id]);
});

it('rejects a negative grace-days override instead of silently widening the purge', function (): void {
    // The bug this guards against: "-{$graceDays} days" with $graceDays = -5 builds the
    // literal string "--5 days", which PHP's relative-date parser resolves as "+5 days" -
    // a WIDER purge than the default, silently, rather than an error. A user soft-deleted
    // only yesterday must never be at risk from a typo like this.
    $recentlyDeleted = softDeletedDaysAgo(1);

    runArtisan('users:purge-deleted', ['--grace-days' => -5])->assertFailed();

    assertSoftDeleted('users', ['id' => $recentlyDeleted->id]);
});

it('rejects --grace-days=0 without --force', function (): void {
    $user = softDeletedDaysAgo(1);

    runArtisan('users:purge-deleted', ['--grace-days' => 0])->assertFailed();

    assertSoftDeleted('users', ['id' => $user->id]);
});

it('purges everything immediately when --grace-days=0 is paired with --force', function (): void {
    // "Purge everyone right now" is a legitimate operator intent (e.g. acting on a
    // compliance request) - rejecting 0 unconditionally would remove a real capability,
    // not just a footgun. --force is the one keystroke that turns this back on.
    $user = softDeletedDaysAgo(1);

    runArtisan('users:purge-deleted', ['--grace-days' => 0, '--force' => true])->assertSuccessful();

    assertModelMissing($user);
});
