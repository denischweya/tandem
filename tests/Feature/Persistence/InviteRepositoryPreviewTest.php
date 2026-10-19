<?php

declare(strict_types=1);

use App\Application\Plans\InviteRepository;
use App\Domain\Plans\MemberRole;
use App\Models\Plan;
use App\Models\PlanInvite;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// §10.3's preview, deliberately separate from InviteRepositoryTest.php (Task 10's own file) -
// this task's Files list names the two apart. planId, planTitle and inviterName below are three
// mutually distinguishable values (a uuid, 'Camping trip', 'Ada Lovelace'), not the null/null
// shape Task 10 shipped wrong for a sibling pair (task-14-context.md §1c): a named-argument
// swap of planId<->planTitle or planTitle<->inviterName inside findPreviewByToken()'s
// `new InvitePreview(...)` call is provable against this fixture - see this file's break-step
// record in task-14-report.md.
it('finds a preview by token, with the inviter\'s name and the active member count', function (): void {
    $plan = Plan::factory()->create(['title' => 'Camping trip']);
    $inviter = User::factory()->hasProfile(['display_name' => 'Ada Lovelace'])->create();
    member($plan, $inviter, MemberRole::Owner, '2026-09-01 09:00:00');
    member($plan, User::factory()->create(), MemberRole::Member, '2026-09-01 09:00:00');
    PlanMember::factory()->create(['plan_id' => $plan->id, 'status' => 'left', 'joined_at' => '2026-08-01 09:00:00']);

    $token = bin2hex(random_bytes(32));
    PlanInvite::factory()->forToken($token)->create(['plan_id' => $plan->id, 'invited_by' => $inviter->id]);

    // R6: plaintext in - the repository hashes internally, so the test never forms the hash.
    $preview = app(InviteRepository::class)->findPreviewByToken($token);

    // G6 (plan-corrections.md Part G, item 5): expect(...)->not->toBeNull() does not narrow a
    // nullable for PHPStan, and findPreviewByToken() returns ?InvitePreview - the brief's own
    // bare $preview->planId trips `property.nonObject` at level 8. The explicit throw is the
    // shipped pattern PlanRepositoryTest and PlanMemberRepositoryTest already use for the same
    // narrowing, and it is real control-flow narrowing, not a cast or an annotation.
    if ($preview === null) {
        throw new RuntimeException('findPreviewByToken() must find the invite this test just created.');
    }

    expect($preview->planId)->toBe($plan->id, 'planId must come from plans.id, not plans.title or user_profiles.display_name');
    expect($preview->planTitle)->toBe('Camping trip', 'planTitle must come from plans.title, not plans.id or user_profiles.display_name');
    expect($preview->inviterName)->toBe('Ada Lovelace', 'inviterName must come from user_profiles.display_name, not plans.title');
    expect($preview->memberCount)->toBe(2, 'the left row must not be counted');
});

it('renders inviter_name null rather than disappearing, for a purged inviter (S-3)', function (): void {
    $plan = Plan::factory()->create();
    $token = bin2hex(random_bytes(32));
    PlanInvite::factory()->forToken($token)->create(['plan_id' => $plan->id, 'invited_by' => null]);

    $preview = app(InviteRepository::class)->findPreviewByToken($token);

    // Same G6 narrowing as the case above, and a deliberate change from the brief's own
    // expect(...)->not->toBeNull($message) here: that form does not narrow $preview for
    // PHPStan, and the brief's own leftJoin-break-step wording (E.11) is pinned to the
    // Pest-negated-expectation phrasing that form produces. Throwing changes BOTH the
    // mechanism (real control-flow narrowing) and the break step's observed failure text -
    // see task-14-report.md for the re-measured wording.
    if ($preview === null) {
        throw new RuntimeException(
            'a purged inviter must leave the preview reachable with inviter_name null, not make the '
            .'whole row disappear (S-3)'
        );
    }

    expect($preview->inviterName)->toBeNull(
        'invited_by is SET NULL by the day-30 purge, so there is no profile to join to and the '
        .'name is absent rather than wrong'
    );
});

it('returns null for a token no invite holds', function (): void {
    expect(app(InviteRepository::class)->findPreviewByToken('nothing'))->toBeNull();
});
