<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('returns the Plan to an active member, with the member count and the actor own role', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    $plan->forceFill(['description' => 'Pizza and a film', 'timezone' => 'Africa/Nairobi'])->save();

    $admin = userWithPassword();
    member($plan, $admin, MemberRole::Admin, '2026-02-01T00:00:00+00:00');

    // A third row, status = left, so the member_count assertion below is about the active-only
    // filter and not merely about the number of plan_members rows. Written as a 5-key attribute
    // array because member() hardcodes MemberStatus::Active and takes no status.
    $departed = User::factory()->hasProfile()->create();
    PlanMember::factory()->create([
        'plan_id' => $plan->id,
        'user_id' => $departed->id,
        'role' => MemberRole::Member->value,
        'status' => MemberStatus::Left->value,
        'joined_at' => '2026-01-10T00:00:00+00:00',
    ]);

    [, , $access] = signIn($admin);

    withToken($access)->getJson("/api/v1/plans/{$plan->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $plan->id)
        ->assertJsonPath('data.title', $plan->title)
        ->assertJsonPath('data.description', 'Pizza and a film')
        ->assertJsonPath('data.status', PlanStatus::Active->value)
        ->assertJsonPath('data.timezone', 'Africa/Nairobi')
        ->assertJsonPath('data.owner_id', $owner->id)
        // 2 of the 3 rows: member_count counts status = active only, which is what §6.1 says
        // and what makes it agree with GET /plans/{plan}/members.
        ->assertJsonPath('data.member_count', 2)
        // The actor's own role, which is what a client draws its affordances from (§6.1).
        ->assertJsonPath('data.viewer_role', MemberRole::Admin->value)
        ->assertJsonPath('data.archived_at', null);
});

it('answers one byte-identical 404 for a non-member, an unknown Plan and a malformed id', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    $stranger = userWithPassword();
    [, , $access] = signIn($stranger);

    // The three refusals spec §5.1 collapses into one answer. Byte-identical is the claim, so
    // getContent() and not assertJsonPath: a render closure encodes compactly while Laravel's
    // own handler encodes with JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES, so two 404s that agree
    // on text can still disagree on whitespace - and the router's own 404 for a non-uuid id
    // would additionally echo the request path back at the client.
    $uris = [
        "/api/v1/plans/{$plan->id}",                              // exists, not a member
        '/api/v1/plans/019360c6-0000-7000-8000-000000000000',     // well-formed uuid, no row
        '/api/v1/plans/not-a-uuid',                               // malformed: Postgres 22P02 if unguarded
    ];

    foreach ($uris as $uri) {
        $response = withToken($access)->getJson($uri)->assertNotFound();

        expect($response->getContent())->toBe(
            '{"message":"Plan not found."}',
            "{$uri} must answer the PlanNotVisible render closure's compact body",
        );
    }
});

it('answers 404 for a member whose row is left, removed or still invited', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    foreach ([MemberStatus::Left, MemberStatus::Removed, MemberStatus::Invited] as $status) {
        $user = userWithPassword();

        PlanMember::factory()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'role' => MemberRole::Member->value,
            'status' => $status->value,
            'joined_at' => $status === MemberStatus::Invited ? null : '2026-01-05T00:00:00+00:00',
        ]);

        // Mandatory, not hygiene: Sanctum's guard caches the resolved user for the whole test,
        // so without this the second and third iterations would authenticate as the first
        // user and the loop would assert the same thing three times.
        forgetAuthenticatedUser();

        [, , $access] = signIn($user);

        $response = withToken($access)->getJson("/api/v1/plans/{$plan->id}")->assertNotFound();

        expect($response->getContent())->toBe(
            '{"message":"Plan not found."}',
            "a {$status->value} membership must be indistinguishable from no membership",
        );
    }

    // The rows are still there - UNIQUE(plan_id, user_id) means a departure is a status change,
    // never a delete, which is why the status filter rather than row absence is what refuses.
    expect(PlanMember::query()->where('plan_id', $plan->id)->count())->toBe(4, 'owner + three non-active rows');
});

it('requires authentication', function (): void {
    $plan = ownedPlan(User::factory()->hasProfile()->create());

    getJson("/api/v1/plans/{$plan->id}")->assertUnauthorized();
});

/**
 * HUMAN RULING, 2026-10-19 (task-15-context.md §2, not in the original brief): Str::isUuid() is
 * case-insensitive and Postgres normalises the uuid column, so an uppercase spelling of a real,
 * visible Plan's id would otherwise still reach that Plan. One canonical URL per Plan, and it
 * makes a path id agree with PageCursor's id pattern, which already accepts lowercase only.
 *
 * Enforced at findVisibleTo() - EloquentPlanRepositoryTest covers the guard directly; this is
 * the one HTTP-level proof that the seam actually refuses the request rather than merely the
 * unit under it, using an owner who really can see the Plan in every other respect.
 */
it('answers 404 for a non-canonical-case uuid, even though the Plan is real and visible to the actor', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);

    [, , $access] = signIn($owner);

    // The lowercase id works - establishes the Plan really is visible to this actor, so the
    // refusal below is about case and nothing else. Same token both times: no actor switch, so
    // no forgetAuthenticatedUser() is needed here.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}")->assertOk();

    $response = withToken($access)->getJson('/api/v1/plans/'.strtoupper($plan->id))->assertNotFound();

    expect($response->getContent())->toBe(
        '{"message":"Plan not found."}',
        'an uppercase variant of a real, visible Plan id must be indistinguishable from an unknown Plan',
    );
});
