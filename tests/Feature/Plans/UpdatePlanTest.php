<?php

declare(strict_types=1);

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('lets an owner change title, description and timezone, and records the field names only', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", [
        'title' => 'Dinner, moved',
        'description' => 'Now with pudding',
    ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Dinner, moved')
        ->assertJsonPath('data.description', 'Now with pudding');

    $activity = PlanActivity::query()->where('activity_type', ActivityType::PlanUpdated->value)->sole();

    // Field NAMES, never values. plan_activity is immutable and readable by every member, so a
    // value written here is permanently visible to everyone who can read the Plan - and a later
    // cycle adding a field that is not uniformly visible would make every past value of it
    // permanently public with no update path to remove it.
    expect($activity->metadata)->toBe(['fields' => ['title', 'description']]);
    expect($activity->actor_id)->toBe($owner->id);
    expect($activity->subject_type)->toBeNull('a plan.* row needs no subject');
    expect($activity->subject_id)->toBeNull();
});

it('lets an admin edit and refuses a plain member with 403', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    $admin = userWithPassword();
    member($plan, $admin, MemberRole::Admin, '2026-02-01T00:00:00+00:00');

    $plain = userWithPassword();
    member($plan, $plain, MemberRole::Member, '2026-03-01T00:00:00+00:00');

    [, , $adminAccess] = signIn($admin);

    withToken($adminAccess)->patchJson("/api/v1/plans/{$plan->id}", ['title' => 'Admin can edit'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Admin can edit');

    forgetAuthenticatedUser();

    [, , $plainAccess] = signIn($plain);

    // A genuine 403, not a 404: this caller can see the Plan, so there is nothing left to
    // disclose by admitting it exists. The body is AuthorizationException's default, which
    // Laravel's handler PRETTY-prints - so this is assertJsonPath and never a byte comparison.
    withToken($plainAccess)->patchJson("/api/v1/plans/{$plan->id}", ['title' => 'Member cannot'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    expect(Plan::query()->sole()->title)->toBe('Admin can edit', 'the refused PATCH must not have written');
});

it('answers 404 to a non-member', function (): void {
    $plan = ownedPlan(User::factory()->hasProfile()->create());

    $stranger = userWithPassword();
    [, , $access] = signIn($stranger);

    $response = withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => 'Not yours'])
        ->assertNotFound();

    expect($response->getContent())->toBe('{"message":"Plan not found."}');
});

it('refuses an archived Plan with 409 and writes nothing', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    $plan->forceFill(['status' => PlanStatus::Archived->value, 'archived_at' => '2026-05-01T00:00:00+00:00'])->save();

    [, , $access] = signIn($owner);

    // 409 and not 404 (the member can see the Plan; GET works, and answering 404 to PATCH and
    // 200 to GET on one path is incoherent), not 403 (the refusal is about the Plan's state, and
    // the owner is refused exactly as a member is), and not 422 (nothing about the request's
    // fields is wrong - this codebase already has one overloaded 422 and that case is the
    // argument against a second).
    //
    // getContent(), because the 409 comes from a render() closure and therefore encodes
    // compactly, unlike the 403 two tests above.
    $response = withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => 'Too late'])
        ->assertStatus(409);

    expect($response->getContent())->toBe('{"message":"This Plan has been archived."}');
    expect(Plan::query()->sole()->title)->toBe($plan->title);
    expect(PlanActivity::query()->count())->toBe(0);
});

it('refuses a cancelled Plan with 409 too, not only an archived one', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    // plans.status carries no CHECK constraint, so this state is constructible even though no
    // Wave 2 write path produces it (spec §7.3). The predicate this pins is "anything but
    // active", not "archived specifically" - without this case, reverting to
    // `=== PlanStatus::Archived->value` passes the whole suite.
    $plan->forceFill(['status' => PlanStatus::Cancelled->value])->save();

    [, , $access] = signIn($owner);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => 'Also too late'])
        ->assertStatus(409);
});

it('answers 403 and not 409 when a plain member edits an archived Plan', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    $plan->forceFill(['status' => PlanStatus::Archived->value, 'archived_at' => '2026-05-01T00:00:00+00:00'])->save();

    $plain = userWithPassword();
    member($plan, $plain, MemberRole::Member, '2026-03-01T00:00:00+00:00');

    [, , $access] = signIn($plain);

    // The ordering decision, asserted: UpdatePlan checks the role before the Plan's state, so a
    // caller who may never edit gets the same answer whatever the Plan's lifecycle is doing.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => 'Nope'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');
});

it('treats an empty body and a no-op change as a success that writes nothing', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    $before = Plan::query()->sole()->updated_at->format('Y-m-d H:i:s.u');

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", [])
        ->assertOk()
        ->assertJsonPath('data.title', $plan->title);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => $plan->title])
        ->assertOk();

    // updated_at is GET /plans's sort key, so a no-op PATCH that touched it would silently
    // reorder the caller's own list. timestamptz(6), so the comparison is to the microsecond.
    expect(Plan::query()->sole()->updated_at->format('Y-m-d H:i:s.u'))
        ->toBe($before, 'a write-nothing PATCH must not move updated_at');
    expect(PlanActivity::query()->count())
        ->toBe(0, 'plan_activity is immutable, so a row for a change that changed nothing can never be corrected');
});

it('leaves every column it was not given alone', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", [
        'title' => 'Only the title',
        'owner_id' => User::factory()->hasProfile()->create()->id,
        'status' => PlanStatus::Archived->value,
        'visibility' => Visibility::Private->value,
        'group_id' => '019360c6-0000-7000-8000-000000000000',
        'cover_image_path' => 'covers/evil.png',
    ])->assertOk();

    $fresh = Plan::query()->sole();

    expect($fresh->title)->toBe('Only the title');
    expect($fresh->owner_id)->toBe($owner->id, 'ownership moves only through spec §9.3');
    expect($fresh->status)->toBe(PlanStatus::Active->value, 'status moves only through POST /archive');
    expect($fresh->visibility)->toBe(Visibility::InviteOnly->value, 'visibility is published read-only in Cycle 1');
    expect($fresh->group_id)->toBeNull('§12.2');
    expect($fresh->cover_image_path)->toBeNull('spec §15');
    expect($fresh->timezone)->toBe($plan->timezone, 'an unsupplied field is unchanged, which is what makes this a PATCH');
    expect($fresh->description)->toBeNull();
});

it('rejects a blank title, a whitespace-only title, an over-long title and a non-IANA timezone', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    // '' arrives as null through the GLOBAL ConvertEmptyStringsToNull middleware and is then
    // refused by `required`, which is what makes 'sometimes|required' mean "omit the key to
    // leave it alone, and you may not clear it". assertJsonValidationErrors() with the message,
    // not assertJsonValidationErrorFor(): the latter is satisfied by ANY failing rule on the
    // field, and `string` alone already refuses null - so a weaker assertion here would not
    // tell a required-rule refusal apart from a string-rule one, which is exactly the gap this
    // case exists to close.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title' => 'The title field is required.']);

    // CORRECTED (H9): this used to read "'   ' survives ConvertEmptyStringsToNull (exact-match
    // only: '' !== '   ') and would skip every NON-implicit rule under
    // Validator::presentOrRuleIsImplicit()". Measured false through this real HTTP request:
    // TrimStrings runs BEFORE ConvertEmptyStringsToNull in Laravel 13's default global stack
    // (CreatePlanTest's own "rejects a missing title..." case states this correctly for the
    // identical shape), so `'   '` is trimmed to `''` and then converted to `null` before this
    // request's rules() ever run - the same present-null shape the blank-title case above
    // exercises, not a second one surviving as whitespace. `required` refuses it via its
    // `is_null($value)` branch rather than its `trim($value) === ''` one, and - exactly as the
    // comment on the blank-title case above already says - `string` independently refuses the
    // same null too. The message pin is what proves `required`'s own message is among the
    // errors; it does not claim `required` is the only rule failing, only that it is one of
    // them, same as the case above.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => '   '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title' => 'The title field is required.']);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => str_repeat('a', 256)])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('title');

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['timezone' => 'Mars/Olympus_Mons'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('timezone');

    expect(Plan::query()->sole()->title)->toBe($plan->title);
});

it('requires authentication', function (): void {
    $plan = ownedPlan(User::factory()->hasProfile()->create());

    patchJson("/api/v1/plans/{$plan->id}", ['title' => 'Nope'])->assertUnauthorized();
});
