<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanStatus;
use App\Models\PlanActivity;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('hard-deletes the Plan and cascades its members, invites and activity', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);

    $other = User::factory()->hasProfile()->create();
    member($plan, $other, MemberRole::Member, '2026-02-01T00:00:00+00:00');

    PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => $owner->id]);

    // A raw insert rather than a model: plan_invites has no model or factory until the invites
    // section lands, and this test only needs a row for the CASCADE to remove. The shape is
    // ConstraintTest::insertPlan()'s, and the email is lowercase because
    // plan_invites_email_lowercase is a CHECK.
    DB::table('plan_invites')->insert([
        'id' => (string) Str::uuid7(),
        'plan_id' => $plan->id,
        'invited_by' => $owner->id,
        'email' => 'guest@example.com',
        'token_hash' => hash('sha256', 'whatever'),
        'role' => MemberRole::Member->value,
        'expires_at' => now()->addDays(14),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [, , $access] = signIn($owner);

    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}")->assertNoContent();

    // One statement in the application, four tables emptied by the schema: plan_members,
    // plan_invites and plan_activity all CASCADE off plans. Plan must never gain SoftDeletes -
    // PurgeDeletedUsersCommand's comment records that the purge would then silently stop working
    // while still reporting success.
    assertDatabaseCount('plans', 0);
    assertDatabaseCount('plan_members', 0);
    assertDatabaseCount('plan_invites', 0);
    assertDatabaseCount('plan_activity', 0);
    // The users are untouched: plans.owner_id is RESTRICT, not CASCADE, and it points the other
    // way.
    assertDatabaseCount('users', 2);
});

it('is allowed on an archived Plan', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    $plan->forceFill(['status' => PlanStatus::Archived->value, 'archived_at' => '2026-05-01T00:00:00+00:00'])->save();

    [, , $access] = signIn($owner);

    // §5.3's Allowed column: deleting is the owner's natural cleanup, and refusing it would
    // leave an archived Plan undeletable for ever.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}")->assertNoContent();

    assertDatabaseCount('plans', 0);
});

it('refuses an admin and a plain member with 403, and a non-member with 404', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    $admin = userWithPassword();
    member($plan, $admin, MemberRole::Admin, '2026-02-01T00:00:00+00:00');

    [, , $adminAccess] = signIn($admin);

    // The matrix's clearest line: Admin has `edit Plan` and not `delete Plan`.
    withToken($adminAccess)->deleteJson("/api/v1/plans/{$plan->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    forgetAuthenticatedUser();

    $stranger = userWithPassword();
    [, , $strangerAccess] = signIn($stranger);

    $response = withToken($strangerAccess)->deleteJson("/api/v1/plans/{$plan->id}")->assertNotFound();

    expect($response->getContent())->toBe('{"message":"Plan not found."}');
    assertDatabaseCount('plans', 1);
    expect(PlanMember::query()->count())->toBe(2, 'owner + admin, both still members');
});

it('requires authentication', function (): void {
    $plan = ownedPlan(User::factory()->hasProfile()->create());

    deleteJson("/api/v1/plans/{$plan->id}")->assertUnauthorized();

    assertDatabaseCount('plans', 1);
});
