<?php

declare(strict_types=1);

use App\Application\Plans\PlanContext;
use App\Application\Plans\PlanSummary;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;
use App\Models\Plan;
use Illuminate\Support\Carbon;

// Not in Task B's own Test files list. fromContext()'s only production caller is a controller
// this task does not own (R8 - the show route is Task 15, the list route is Task 18), so at
// this commit it has no production consumer at all. Two precedents make an unexercised
// Produces method a real risk rather than a tidy loose end: PHPStan's trait.unused fired on
// ReadsPageQuery for exactly this reason (resolved by PageQueryTestRequest, tests/Pest.php),
// and Task 5 shipped Email::equals() with no test and no caller and it became a deferred
// minor - unverified public API. This file exercises fromContext() directly against a real
// PlanContext, independently of whether a Resource happens to render every field correctly
// (PlanResourceTest only ever constructs PlanSummary directly with named arguments, never
// through fromContext()).
//
// tests/Feature, not tests/Unit, and the call that forces it is Eloquent's, not this file's
// own: forceFill()'s date-cast path for created_at/updated_at/archived_at reaches
// Model::getDateFormat() -> Model::getConnection() -> static::$resolver->connection(), and
// Pest's Unit suite boots no application (tests/Pest.php:26 is `uses(TestCase::class)
// ->in('Feature')` only), so $resolver is null there. Measured: moving this file under
// tests/Unit throws "Call to a member function connection() on null" from inside
// vendor/laravel/framework's Model.php, for both cases, before a single assertion of this
// file's own ever runs.
//
// Every same-typed pair in the fixture below is given a DISTINCT value, per the risk this
// task's context file names explicitly: Task 10 shipped two ?Carbon properties
// (acceptedAt/revokedAt) that a named-argument swap silently exchanged, passing all twelve of
// its tests, because the fixture left both null. This task's analogous pair is
// createdAt/updatedAt (both Carbon) - new Plan() uses HasUuids and $guarded = ['*'], so the
// fixture is built with forceFill(), the one write path this model's own docblock says mass
// assignment guarding does not affect.
function planFixtureForSummary(): Plan
{
    $plan = new Plan;

    $plan->forceFill([
        'id' => '0192f0a1-aaaa-7000-8000-000000000001',
        'owner_id' => '0192f0a1-bbbb-7000-8000-000000000002',
        'group_id' => null,
        'title' => 'Context-derived title',
        'description' => 'Context-derived description',
        'status' => PlanStatus::Archived->value,
        'visibility' => Visibility::Private->value,
        'timezone' => 'America/New_York',
        'cover_image_path' => null,
        'archived_at' => Carbon::parse('2026-07-01 00:00:00+00:00'),
        'created_at' => Carbon::parse('2026-01-01 00:00:00+00:00'),
        'updated_at' => Carbon::parse('2026-02-02 00:00:00+00:00'),
    ]);

    return $plan;
}

it('maps every field of a PlanContext onto the matching PlanSummary field', function (): void {
    $plan = planFixtureForSummary();

    $context = new PlanContext(
        plan: $plan,
        memberCount: 7,
        role: MemberRole::Admin,
        status: MemberStatus::Active,
    );

    $summary = PlanSummary::fromContext($context);

    // Field-by-field, not a single toEqual() against a second PlanSummary: a transposition
    // between two same-typed fields (createdAt/updatedAt) would still produce "a PlanSummary
    // with the right twelve values in the wrong two slots", which a whole-object comparison
    // against a hand-built expected PlanSummary would only catch if that expected object
    // itself used distinct values - which is exactly what this fixture does, and what the
    // per-field assertions below each pin independently.
    expect($summary->id)->toBe($plan->id)
        ->and($summary->title)->toBe('Context-derived title')
        ->and($summary->description)->toBe('Context-derived description')
        ->and($summary->status)->toBe(PlanStatus::Archived, 'status comes from plan.status, not from the MemberStatus on the context')
        ->and($summary->visibility)->toBe(Visibility::Private)
        ->and($summary->timezone)->toBe('America/New_York')
        ->and($summary->ownerId)->toBe('0192f0a1-bbbb-7000-8000-000000000002')
        ->and($summary->memberCount)->toBe(7, 'memberCount comes from the context, not from a count on the plan')
        ->and($summary->viewerRole)->toBe(MemberRole::Admin, 'viewerRole comes from context.role, not from any plan column')
        ->and($summary->archivedAt?->toIso8601String())->toBe('2026-07-01T00:00:00+00:00')
        ->and($summary->createdAt->toIso8601String())->toBe('2026-01-01T00:00:00+00:00', 'createdAt must be plan.created_at, not plan.updated_at')
        ->and($summary->updatedAt->toIso8601String())->toBe('2026-02-02T00:00:00+00:00', 'updatedAt must be plan.updated_at, not plan.created_at');
});

it('maps a null archived_at straight through', function (): void {
    $plan = planFixtureForSummary();
    $plan->archived_at = null;

    $context = new PlanContext($plan, 1, MemberRole::Member, MemberStatus::Active);

    expect(PlanSummary::fromContext($context)->archivedAt)->toBeNull();
});
