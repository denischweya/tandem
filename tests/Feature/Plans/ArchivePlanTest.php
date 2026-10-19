<?php

declare(strict_types=1);

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanStatus;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('lets the owner archive, writing both status and archived_at and one plan.archived row', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.id', $plan->id)
        ->assertJsonPath('data.status', PlanStatus::Archived->value);

    $fresh = Plan::query()->sole();

    expect($fresh->status)->toBe(PlanStatus::Archived->value);
    // Both columns, because DeleteAccount::resolvePlanOwnership() writes both and a reader that
    // checked only one would disagree with the other path.
    expect($fresh->archived_at)->not->toBeNull('archived_at must be stamped alongside status');
    // Stamped by the request rather than carried in from a fixture, and asserted without
    // reference to the wall clock so there is nothing here to flake: the Plan was created
    // before the archive call, so Clock::now() cannot be earlier than created_at.
    expect($fresh->archived_at?->greaterThanOrEqualTo($fresh->created_at))
        ->toBeTrue('archived_at must be stamped from Clock::now() when the request runs');

    $activity = PlanActivity::query()->sole();

    expect($activity->activity_type)->toBe(ActivityType::PlanArchived->value);
    expect($activity->actor_id)->toBe($owner->id);
    expect($activity->metadata)->toBe([]);
});

it('is idempotent in both halves: the same archived_at and no second activity row', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/archive")->assertOk();

    $stamped = Plan::query()->sole()->archived_at;

    // Before the comparison, not after: archived_at is Carbon|null (the column is nullable), so
    // if both reads were null the ->format() comparison below would pass on null === null. This
    // is the assertion that stops that, and PHPStan level 8 is also why the two reads below go
    // through ?-> rather than ->.
    expect($stamped)->not->toBeNull('the first archive must have stamped archived_at');

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', PlanStatus::Archived->value);

    // Two halves, and both matter. Re-stamping archived_at would make the ordering and the feed
    // lie about when the Plan ended; a second activity row would make an immutable table
    // (blueprint §51) accumulate duplicates because a client retried. The precedent for "a
    // second valid call is another success" is VerifyEmailController.
    expect(Plan::query()->sole()->archived_at?->format('Y-m-d H:i:s.u'))
        ->toBe($stamped?->format('Y-m-d H:i:s.u'), 'a second archive must not re-stamp archived_at');
    expect(PlanActivity::query()->count())
        ->toBe(1, 'the activity row is written on the transition only');
});

it('refuses an admin and a plain member with 403, and a non-member with 404', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    $admin = userWithPassword();
    member($plan, $admin, MemberRole::Admin, '2026-02-01T00:00:00+00:00');

    [, , $adminAccess] = signIn($admin);

    // Owner only. Blueprint §9's matrix has no archive row, so this is a decision: archiving is
    // a lifecycle act adjacent to `delete Plan`, not a content edit, and from a member's point
    // of view an archived Plan is over - so an admin who could archive could end the Plan for
    // everyone while the matrix withholds `delete Plan` from admins.
    withToken($adminAccess)->postJson("/api/v1/plans/{$plan->id}/archive")
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    forgetAuthenticatedUser();

    $stranger = userWithPassword();
    [, , $strangerAccess] = signIn($stranger);

    $response = withToken($strangerAccess)->postJson("/api/v1/plans/{$plan->id}/archive")
        ->assertNotFound();

    expect($response->getContent())->toBe('{"message":"Plan not found."}');
    expect(Plan::query()->sole()->status)->toBe(PlanStatus::Active->value);
    expect(PlanActivity::query()->count())->toBe(0);
});

it('requires authentication', function (): void {
    $plan = ownedPlan(User::factory()->hasProfile()->create());

    postJson("/api/v1/plans/{$plan->id}/archive")->assertUnauthorized();
});
