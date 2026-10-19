<?php

declare(strict_types=1);

use App\Application\Plans\InviteRepository;
use App\Application\Plans\PlanInviteNotFound;
use App\Application\Plans\PlanInviteView;
use App\Domain\Plans\MemberRole;
use App\Infrastructure\Persistence\Eloquent\EloquentInviteRepository;
use App\Models\Plan;
use App\Models\PlanInvite;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('binds the invite repository interface to its Eloquent implementation', function (): void {
    expect(app(InviteRepository::class))->toBeInstanceOf(EloquentInviteRepository::class);
});

it('finds an invite that belongs to the Plan', function (): void {
    $plan = Plan::factory()->create();
    $invite = PlanInvite::factory()->create(['plan_id' => $plan->id]);

    expect(app(InviteRepository::class)->findForPlan($plan->id, $invite->id)->id)->toBe($invite->id);
});

it('finds accepted and revoked invites too, because revoking one is a silent 204', function (): void {
    // §10.8: "revoking an already-accepted invite is a silent 204, not a 409", which the use
    // case can only answer if the row is findable.
    $plan = Plan::factory()->create();
    $accepted = PlanInvite::factory()->accepted()->create(['plan_id' => $plan->id]);
    $revoked = PlanInvite::factory()->revoked()->create(['plan_id' => $plan->id]);

    $repository = app(InviteRepository::class);

    expect($repository->findForPlan($plan->id, $accepted->id)->accepted_at)->not->toBeNull();
    expect($repository->findForPlan($plan->id, $revoked->id)->revoked_at)->not->toBeNull();
});

it('refuses an invite that belongs to a different Plan', function (): void {
    // §17.3's third IDOR parameter, and §10.8's "an {invite} belonging to a different Plan →
    // 404". Without the plan_id predicate, any owner or admin can revoke any invite in the
    // system by guessing its id.
    $plan = Plan::factory()->create();
    $otherPlan = Plan::factory()->create();
    $elsewhere = PlanInvite::factory()->create(['plan_id' => $otherPlan->id]);

    expect(fn () => app(InviteRepository::class)->findForPlan($plan->id, $elsewhere->id))
        ->toThrow(PlanInviteNotFound::class);
});

it('refuses an unknown and a malformed invite id without reaching Postgres', function (): void {
    // §5.1's third identifier. Without the shape check this is SQLSTATE[22P02] and a 500.
    $plan = Plan::factory()->create();
    $invite = PlanInvite::factory()->create(['plan_id' => $plan->id]);
    $repository = app(InviteRepository::class);

    expect(fn () => $repository->findForPlan($plan->id, '019a0b7e-7c2a-7f3e-8b10-2f6c4d5e6a7b'))
        ->toThrow(PlanInviteNotFound::class);
    expect(fn () => $repository->findForPlan($plan->id, 'not-a-uuid'))
        ->toThrow(PlanInviteNotFound::class);
    expect(fn () => $repository->findForPlan('not-a-uuid', 'not-a-uuid'))
        ->toThrow(PlanInviteNotFound::class);
    // T10-2: the expectation above passes 'not-a-uuid' in BOTH positions, so
    // ! Str::isUuid($inviteId) alone satisfies it - deleting only the $planId half of the
    // guard's `||` left every other expectation in this test green, because none of them
    // pairs a malformed plan id with a WELL-FORMED invite id. This is the one that can't be
    // satisfied by the $inviteId clause alone.
    expect(fn () => $repository->findForPlan('not-a-uuid', $invite->id))
        ->toThrow(PlanInviteNotFound::class);
});

it('finds an invite by its token and nothing by a token it does not hold', function (): void {
    $token = bin2hex(random_bytes(32));
    $invite = PlanInvite::factory()->forToken($token)->create();

    $repository = app(InviteRepository::class);

    expect($repository->findByToken($token)?->id)->toBe($invite->id);
    expect($repository->findByToken('a different token'))->toBeNull();
});

it('locks the row it reads for the acceptance path', function (): void {
    // §10.7 step 1: "look the invite up by sha256(token) WITH lockForUpdate() - two devices
    // accepting one single-use invite concurrently is the race this must resolve
    // deterministically". A concurrency test cannot see the lock from one connection, so this
    // asserts the statement itself, which is falsifiable: remove lockForUpdate() and the SQL
    // no longer carries it.
    //
    // T10-4: equality against the one captured statement, not toContain() over an imploded
    // haystack of however many queries ran. toContain() can't tell "the lock sits on the row
    // I expected" from "some other statement in the batch happened to carry the words 'for
    // update'", and toHaveCount(1) first closes the companion hole in the other test below -
    // an empty $queries array has no element [0] to compare, so this errors rather than
    // passing vacuously.
    $token = bin2hex(random_bytes(32));
    PlanInvite::factory()->forToken($token)->create();

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    app(InviteRepository::class)->findByTokenForUpdate($token);

    expect($queries)->toHaveCount(1);
    expect($queries[0])->toBe('select * from "plan_invites" where "token_hash" = ? limit 1 for update');
});

it('does not lock the row it reads for the preview path', function (): void {
    // §10.3's preview is unauthenticated and throttled, and takes no lock: it writes nothing,
    // and a lock taken by an unauthenticated read is a lock an unauthenticated caller can hold.
    //
    // T10-4: not->toContain('for update') on an imploded haystack passes on an EMPTY haystack
    // too - a findByToken() that ran no query at all would satisfy it just as well as one that
    // ran the right query without a lock. Asserting the one captured statement by equality
    // closes that hole: there is no way to pass this without emitting exactly the query this
    // method is meant to run.
    $token = bin2hex(random_bytes(32));
    PlanInvite::factory()->forToken($token)->create();

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    app(InviteRepository::class)->findByToken($token);

    expect($queries)->toHaveCount(1);
    expect($queries[0])->toBe('select * from "plan_invites" where "token_hash" = ? limit 1');
});

it('lists a Plan invites newest first with every view field resolved through the invite', function (): void {
    // B10a (wave judge finding, task-10-context.md §1): the brief's own version of this test
    // asserted only id, invitedByName and role, with a fixture that left accepted_at and
    // revoked_at both null. A mutation that swaps the acceptedAt:/revokedAt: named arguments in
    // PlanInviteView::fromInvite() passes that version of the test unchanged - both are null
    // either way, and the fields it never checked (email, expiresAt) are read straight off the
    // model, never through the view. This version asserts all SEVEN PlanInviteView fields,
    // through the view, with accepted_at and revoked_at set to two DIFFERENT timestamps, which
    // is what a swap of the two actually changes.
    $plan = Plan::factory()->create();
    $inviter = User::factory()->hasProfile(['display_name' => 'Ada Lovelace'])->create();

    $acceptedAt = '2026-02-03 09:00:00';
    $revokedAt = '2026-02-04 11:00:00';
    $expiresAt = '2026-03-01 00:00:00';

    $older = PlanInvite::factory()->create(['plan_id' => $plan->id, 'invited_by' => $inviter->id]);
    $newer = PlanInvite::factory()->create([
        'plan_id' => $plan->id,
        'invited_by' => $inviter->id,
        'email' => 'invitee@example.com',
        'role' => MemberRole::Admin->value,
        'expires_at' => $expiresAt,
        // Both set, and to different values: §10.8 answers a revoke of an already-accepted
        // invite with a silent 204, so a row can legitimately carry both, and a row with
        // accepted_at === revoked_at would survive the acceptedAt/revokedAt argument swap
        // undetected.
        'accepted_at' => $acceptedAt,
        'revoked_at' => $revokedAt,
    ]);

    DB::table('plan_invites')->where('id', $older->id)->update(['created_at' => '2026-02-01 10:00:00.000000+00']);
    DB::table('plan_invites')->where('id', $newer->id)->update(['created_at' => '2026-02-02 10:00:00.000000+00']);

    $views = app(InviteRepository::class)->listForPlan($plan->id);

    expect(array_map(fn (PlanInviteView $view) => $view->id, $views))->toBe([$newer->id, $older->id]);

    $view = $views[0];

    // The view carries MemberRole, not the raw column, and nothing on it can reach the model:
    // $view->invite does not exist, and neither does token_hash.
    expect($view->id)->toBe($newer->id);
    expect($view->email)->toBe('invitee@example.com');
    expect($view->role)->toBe(MemberRole::Admin);
    expect($view->expiresAt->toDateTimeString())->toBe($expiresAt);
    expect($view->acceptedAt?->toDateTimeString())->toBe($acceptedAt);
    expect($view->revokedAt?->toDateTimeString())->toBe($revokedAt);
    expect($view->invitedByName)->toBe('Ada Lovelace');
});

it('breaks a created_at tie by id DESC', function (): void {
    // T10-3: the test above gives its two rows DISTINCT created_at values, so
    // orderByDesc('plan_invites.id') never had an observed red - InviteRepository's own
    // docblock claims "created_at DESC, id DESC (§6.3)" with only the first half proven. This
    // fixture ties created_at on purpose, so the tiebreak is the only thing that can decide
    // the order; $expected is computed from the two ids themselves (string-compared the same
    // way Postgres orders a uuid column, left to right, since the canonical textual form puts
    // hyphens at the same fixed positions in every id), not assumed from creation order.
    $plan = Plan::factory()->create();

    $first = PlanInvite::factory()->create(['plan_id' => $plan->id]);
    $second = PlanInvite::factory()->create(['plan_id' => $plan->id]);

    DB::table('plan_invites')->whereIn('id', [$first->id, $second->id])
        ->update(['created_at' => '2026-02-06 10:00:00.000000+00']);

    $expected = [$first->id, $second->id];
    usort($expected, fn (string $a, string $b): int => strcmp($b, $a));

    $views = app(InviteRepository::class)->listForPlan($plan->id);

    expect(array_map(fn (PlanInviteView $view) => $view->id, $views))->toBe($expected);
});

it('lists an invite whose inviter has been hard-deleted, with a null name', function (): void {
    // S-3, and it is reachable through Wave 2 own list route: plan_invites.invited_by is
    // nullable with SET NULL, fired by the day-30 purge's forceDelete(), and §14.1 clause 1
    // revokes only UNACCEPTED invites at soft-delete time - so an accepted invite keeps its row,
    // stays listed here, and loses its inviter 30 days later.
    $plan = Plan::factory()->create();
    $invite = PlanInvite::factory()->accepted()->create(['plan_id' => $plan->id, 'invited_by' => null]);

    $views = app(InviteRepository::class)->listForPlan($plan->id);

    expect($views)->toHaveCount(1);
    expect($views[0]->id)->toBe($invite->id);
    expect($views[0]->invitedByName)->toBeNull();
});

it('lists no invite from another Plan, and nothing for a malformed id', function (): void {
    $plan = Plan::factory()->create();
    PlanInvite::factory()->create(['plan_id' => Plan::factory()->create()->id]);

    $repository = app(InviteRepository::class);

    expect($repository->listForPlan($plan->id))->toBe([]);
    expect($repository->listForPlan('not-a-uuid'))->toBe([]);
});

it('lists every invite of a Plan in exactly one query', function (): void {
    // The inviter display names are one join for the whole list, not one query per row - the
    // same obligation §8.1 states for the member list.
    $plan = Plan::factory()->create();

    foreach (range(1, 4) as $ignored) {
        $inviter = User::factory()->hasProfile()->create();
        PlanInvite::factory()->create(['plan_id' => $plan->id, 'invited_by' => $inviter->id]);
    }

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $views = app(InviteRepository::class)->listForPlan($plan->id);

    expect($views)->toHaveCount(4);
    expect($queries)->toHaveCount(1);
});
