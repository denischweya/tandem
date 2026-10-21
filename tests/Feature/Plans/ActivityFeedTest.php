<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

/**
 * GET /api/v1/plans/{plan}/activity - spec §11.
 *
 * Several of these cases cannot be replaced by a status assertion, and each is here because a
 * measurement showed the failure it protects against:
 *
 *   - "pages across a tie" fails if the keyset predicate is spelled as a plain created_at
 *     comparison rather than the row-comparison form over (created_at, id). Eloquent writes
 *     created_at to the second (Grammar::getDateFormat() is 'Y-m-d H:i:s'), so two rows written
 *     in one request share one created_at and a predicate without the id tiebreak drops one of
 *     them from every page after the first.
 *   - "ranks created_at ahead of id" fails if the ORDER BY ever drops created_at and relies on
 *     id alone - every other fixture in this file creates its rows in created_at order, so id
 *     order (ids are UUIDv7, time-ordered) agrees with created_at order by construction and
 *     cannot, on its own, tell the two keys apart.
 *   - "one query, whatever the page size" fails if anything resolves actor_name per row. An N+1
 *     and a batch load return byte-identical JSON, so only a query count can tell them apart.
 *   - "no write route" is the mechanical form of blueprint §51's immutability: there is no
 *     update path and no delete path for an activity row.
 *
 * Imports arrive with the case that needs them, because Pint's Laravel preset includes
 * no_unused_imports.
 */
it('returns the plan activity newest first for an active member', function (): void {
    [$user, , $access] = signIn();

    $plan = Plan::factory()->create(['owner_id' => $user->id]);
    member($plan, $user, MemberRole::Owner, '2026-01-01 00:00:00');

    $oldest = PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $user->id,
        'activity_type' => 'plan.created',
        'created_at' => '2026-02-01 10:00:00',
    ]);
    $middle = PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $user->id,
        'activity_type' => 'member.joined',
        'subject_type' => 'User',
        'subject_id' => $user->id,
        'created_at' => '2026-02-01 11:00:00',
    ]);
    $newest = PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $user->id,
        'activity_type' => 'plan.updated',
        'metadata' => ['fields' => ['title']],
        'created_at' => '2026-02-01 12:00:00',
    ]);

    // A second Plan's row, so "newest first" is not the only thing being asserted: a feed that
    // forgot its plan_id predicate would put this row at the top.
    $other = Plan::factory()->create();
    PlanActivity::factory()->create([
        'plan_id' => $other->id,
        'created_at' => '2026-03-01 00:00:00',
    ]);

    withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        // The structure assertion is not decoration and it is not a duplicate of the paths
        // below. assertJsonPath('meta.next_cursor', null) goes through data_get(), which answers
        // null for a MISSING key as happily as for a null one - so on its own it passes when
        // `meta` is absent entirely, which is exactly the trap of a collection published without
        // ->additional() (still a well-formed {"data": [...]}). assertJsonStructure checks key
        // PRESENCE, so it is what makes the null assertion mean something. The per-row key list
        // is here for the same reason: a Resource that omitted actor_name when it is null -
        // $this->when() does exactly that - would otherwise pass every actor_name assertion in
        // this file.
        ->assertJsonStructure([
            'data' => [
                ['id', 'activity_type', 'actor_id', 'actor_name', 'subject_type', 'subject_id', 'metadata', 'created_at'],
            ],
            'meta' => ['next_cursor'],
        ])
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('data.0.activity_type', 'plan.updated')
        ->assertJsonPath('data.0.metadata.fields', ['title'])
        ->assertJsonPath('data.1.id', $middle->id)
        ->assertJsonPath('data.1.subject_type', 'User')
        ->assertJsonPath('data.1.subject_id', $user->id)
        ->assertJsonPath('data.2.id', $oldest->id)
        ->assertJsonPath('data.2.actor_id', $user->id)
        ->assertJsonPath('data.2.actor_name', $user->profile->display_name)
        ->assertJsonPath('meta.next_cursor', null);
});

it('answers 404 with the one Plan body for a signed-in non-member', function (): void {
    [, , $access] = signIn();

    $plan = Plan::factory()->create();
    PlanActivity::factory()->create(['plan_id' => $plan->id, 'created_at' => '2026-02-01 10:00:00']);

    // The body, byte for byte, and not just the status: a 403 would confirm the Plan exists, and
    // so would a different 404 body. This assertion is the whole of spec §8.1 for this route.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity")
        ->assertNotFound()
        ->assertExactJson(['message' => 'Plan not found.']);
});

it('answers 404 with the same body for a non-uuid plan id', function (): void {
    [, , $access] = signIn();

    // A non-uuid value against the uuid plan_id column is Postgres 22P02, which
    // Handler::prepareException() does not map and which renders as a 500 with
    // {"message": "Server Error"} - so the identifier's shape is validated inside the
    // repository seam (findVisibleTo()), which is also why Route::whereUuid('plan') is NOT
    // used: the router's own 404 body is different AND pretty-printed, so one route would
    // answer two distinguishable 404s.
    withToken($access)->getJson('/api/v1/plans/not-a-uuid/activity')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Plan not found.']);
});

it('answers 404 for a member whose row is left rather than active', function (): void {
    [$user, , $access] = signIn();

    $plan = Plan::factory()->create();
    PlanActivity::factory()->create(['plan_id' => $plan->id, 'created_at' => '2026-02-01 10:00:00']);

    // A departed member keeps a plan_members row (UNIQUE(plan_id, user_id) forces reuse of it),
    // so "has a row" is not "may read". grantsAccess() is what decides, inside findVisibleTo().
    // forceFill, not fill: PlanMember is $guarded = ['*'], which makes totallyGuarded() true, so
    // fill() throws MassAssignmentException on the first attribute. forceFill() is
    // unguarded(fn () => fill(...)). And MemberStatus::Left->value rather than the literal
    // 'left', so a case renamed without the column being migrated breaks here instead of seeding
    // a value nothing recognises.
    $row = member($plan, $user, MemberRole::Member, '2026-01-01 00:00:00');
    $row->forceFill(['status' => MemberStatus::Left->value])->save();

    withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity")
        ->assertNotFound()
        ->assertExactJson(['message' => 'Plan not found.']);
});

it('requires authentication', function (): void {
    $plan = Plan::factory()->create();

    getJson("/api/v1/plans/{$plan->id}/activity")->assertUnauthorized();
});

it('does not require a verified email', function (): void {
    // The `verified` gate covers no read (spec §7.5). An unverified active member must be able
    // to read the feed of a Plan they are in - otherwise a user who joined through an invite
    // link on an unverified account is refused on the Plan they joined a second earlier.
    $user = User::factory()->unverified()->hasProfile()
        ->create(['password_hash' => Hash::make('correct horse battery staple')]);

    [, , $access] = signIn($user);

    $plan = Plan::factory()->create();
    member($plan, $user, MemberRole::Member, '2026-01-01 00:00:00');
    PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $user->id,
        'created_at' => '2026-02-01 10:00:00',
    ]);

    withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects a page size above the cap with 422', function (): void {
    [$user, , $access] = signIn();

    $plan = Plan::factory()->create(['owner_id' => $user->id]);
    member($plan, $user, MemberRole::Owner, '2026-01-01 00:00:00');

    // Not a duplicate of the FormRequest's own unit test: this is the only assertion that the
    // controller really takes ListPlanActivityRequest. A controller typed `Request` would
    // answer 200 here and nothing else in the suite would notice.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity?limit=101")
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('limit');
});

it('publishes no write route for the activity feed', function (): void {
    // Blueprint §51 and spec §11.2: an activity row is immutable - no update path, no delete
    // path, no route. Read off Laravel's own route table rather than a hand-written list, so a
    // route added later fails this instead of being skipped by it.
    //
    // str_contains(), not str_ends_with(): a filter anchored to the END of the uri only catches
    // a write route whose own path ends in literally "/activity" - it does not catch the shape
    // a write on one activity ROW would actually take,
    // DELETE /plans/{plan}/activity/{activity}, whose uri does not end with "/activity" at all.
    // That is the likelier shape of the thing this case exists to forbid, so the filter has to
    // match the segment anywhere in the uri, not only at its tail.
    $methods = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_contains($route->uri(), '/activity')) {
            foreach ($route->methods() as $method) {
                $methods[] = $method.' '.$route->uri();
            }
        }
    }

    sort($methods);

    expect($methods)->toBe([
        'GET api/v1/plans/{plan}/activity',
        'HEAD api/v1/plans/{plan}/activity',
    ]);
});

it('pages across two rows that share a created_at without dropping or repeating either', function (): void {
    [$user, , $access] = signIn();

    $plan = Plan::factory()->create(['owner_id' => $user->id]);
    member($plan, $user, MemberRole::Owner, '2026-01-01 00:00:00');

    // One created_at for both rows, which is the common case and not a contrived one: Eloquent
    // writes created_at through Grammar::getDateFormat() = 'Y-m-d H:i:s', so every row written
    // inside one request shares a second - and an ownership-transfer path can write two
    // member.role_changed rows in one transaction. The cursor's second key is `id`, and this is
    // the case that fails without it.
    $first = PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $user->id,
        'created_at' => '2026-02-01 10:00:00',
    ]);
    $second = PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $user->id,
        'created_at' => '2026-02-01 10:00:00',
    ]);

    $pageOne = withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity?limit=1")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $cursor = $pageOne->json('meta.next_cursor');
    $firstId = $pageOne->json('data.0.id');

    expect($cursor)->toBeString();

    $pageTwo = withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity?limit=1&cursor=".urlencode((string) $cursor))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $secondId = $pageTwo->json('data.0.id');

    // Disjoint and complete: the two pages together are exactly the two rows, in id-descending
    // order within the shared created_at. Asserted as a set rather than by naming which row
    // comes first, because the ids are UUIDv7 values the test did not choose. This case opposes
    // insertion order against id order - $first is created before $second, so a tiebreak that
    // silently fell back to physical/TID order would return $first on page one, while the
    // assertion below requires the row with the LEXICOGRAPHICALLY LARGER id (which, since
    // UUIDv7 ids are time-ordered and $second was created after $first, is $second) on page one
    // instead.
    $seen = [$firstId, $secondId];
    sort($seen);
    $expected = [$first->id, $second->id];
    sort($expected);

    expect($seen)->toBe($expected)
        ->and(strcmp((string) $firstId, (string) $secondId))->toBeGreaterThan(0)
        ->and($pageTwo->json('meta.next_cursor'))->toBeNull();
});

// Extra, beyond this task's thirteen brief-prescribed cases - added because every other fixture
// in this file creates its rows in created_at order, so in every one of them id order (UUIDv7
// ids are time-ordered) and created_at order AGREE by construction. That means none of the
// other cases can tell "sorted by created_at" apart from "sorted by id, with created_at never
// consulted" - deleting `orderByDesc('created_at')` from the repository and leaving
// `orderByDesc('id')` in place would still satisfy every assertion above, because the free
// order and the id order happen to agree on all of them. This case forces the two keys to
// disagree, so it is the only one in this file that can tell them apart.
it('ranks created_at ahead of id when the two disagree', function (): void {
    [$user, , $access] = signIn();

    $plan = Plan::factory()->create(['owner_id' => $user->id]);
    member($plan, $user, MemberRole::Owner, '2026-01-01 00:00:00');

    // Created FIRST, so UUIDv7 gives it the SMALLER id - but stamped with the LATER created_at.
    $laterByTimestamp = PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $user->id,
        'created_at' => '2026-02-01 12:00:00',
    ]);
    // Created SECOND, so UUIDv7 gives it the LARGER id - but stamped with the EARLIER
    // created_at. If id order governed the feed (the shape a deleted `created_at` key in the
    // ORDER BY would produce), this row would rank FIRST despite having the older created_at.
    $earlierByTimestamp = PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $user->id,
        'created_at' => '2026-02-01 09:00:00',
    ]);

    // This case is void unless the row created second really did get the larger id - checked
    // rather than assumed, since the whole point of the case depends on it.
    expect(strcmp($earlierByTimestamp->id, $laterByTimestamp->id))->toBeGreaterThan(
        0,
        'this case is void unless the row created second (the earlier created_at) got the larger id',
    );

    withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $laterByTimestamp->id)
        ->assertJsonPath('data.1.id', $earlierByTimestamp->id);
});

it('renders an anonymised row with a null actor and a null name', function (): void {
    [$user, , $access] = signIn();

    $plan = Plan::factory()->create(['owner_id' => $user->id]);
    member($plan, $user, MemberRole::Owner, '2026-01-01 00:00:00');

    // The shape account deletion's anonymisation step leaves behind: UPDATE plan_activity SET
    // actor_id = NULL. The row is never removed, so the feed stays intelligible to the members
    // who remain, and PlanActivity deliberately has no actor() relation - an anonymised row has
    // no actor to return. The client renders the placeholder; the API publishes null, because a
    // raw fallback from the server could not be localised.
    PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => null,
        'activity_type' => 'member.left',
        'subject_type' => 'User',
        'subject_id' => $user->id,
        'created_at' => '2026-02-01 10:00:00',
    ]);

    withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        // Presence first, then the value: assertJsonPath(..., null) passes on a missing key, so
        // without this a Resource that omitted either field for a null actor would satisfy the
        // two assertions below. These are published as null and not omitted.
        ->assertJsonStructure(['data' => [['actor_id', 'actor_name']]])
        ->assertJsonPath('data.0.activity_type', 'member.left')
        ->assertJsonPath('data.0.actor_id', null)
        ->assertJsonPath('data.0.actor_name', null)
        ->assertJsonPath('data.0.subject_id', $user->id);
});

it('renders a null actor_name for an actor whose profile no longer exists', function (): void {
    [$user, , $access] = signIn();

    $plan = Plan::factory()->create(['owner_id' => $user->id]);
    member($plan, $user, MemberRole::Owner, '2026-01-01 00:00:00');

    $ghost = User::factory()->hasProfile()->create();

    PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $ghost->id,
        'activity_type' => 'member.joined',
        'subject_type' => 'User',
        'subject_id' => $ghost->id,
        'created_at' => '2026-02-01 10:00:00',
    ]);

    // The third actor state: plan_activity.actor_id carries no foreign key, so a non-null
    // actor_id with no user_profiles row behind it is representable. The renderer must not
    // dereference a missing profile either way. Deleting the profile row directly, because no
    // application path produces this and the point is that the renderer survives it anyway.
    DB::table('user_profiles')->where('user_id', $ghost->id)->delete();

    withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity")
        ->assertOk()
        ->assertJsonStructure(['data' => [['actor_id', 'actor_name']]])
        ->assertJsonPath('data.0.actor_id', $ghost->id)
        ->assertJsonPath('data.0.actor_name', null);
});

it('keeps the actor_id and the actor_name of an actor whose account is merely suspended', function (): void {
    [$user, , $access] = signIn();

    $plan = Plan::factory()->create(['owner_id' => $user->id]);
    member($plan, $user, MemberRole::Owner, '2026-01-01 00:00:00');

    // A row whose actor is merely suspended keeps its actor_id and its name: suspension is not
    // deletion, and account-deletion's anonymisation is scoped to account deletion. The
    // suspended user is the ROW'S ACTOR and not the viewer, deliberately: a suspended user's
    // already-issued access token still works, so signing in as one would test Sanctum rather
    // than attribution, and attribution is what this case is about.
    //
    // suspended_at is a real column on users - timestamptz(6) NULL - with no factory state and
    // no entry in User::casts(), so it is set through the factory's own unguarded create() as a
    // literal timestamp string. This case passes today because nothing in the rendering path
    // reads that column, which is why it is here: a future "hide suspended actors" change is
    // then a red test rather than a silent change of published data.
    $suspended = User::factory()
        ->hasProfile(['display_name' => 'Sal Suspended'])
        ->create(['suspended_at' => '2026-01-15 09:00:00']);

    PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $suspended->id,
        'activity_type' => 'member.joined',
        'subject_type' => 'User',
        'subject_id' => $suspended->id,
        'created_at' => '2026-02-01 10:00:00',
    ]);

    withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        // Presence before value, as everywhere else in this file: assertJsonPath() goes through
        // data_get(), which cannot tell a null value from a missing key, so a Resource that
        // omitted either field would satisfy the two assertions below without this one.
        ->assertJsonStructure(['data' => [['actor_id', 'actor_name']]])
        ->assertJsonPath('data.0.actor_id', $suspended->id)
        ->assertJsonPath('data.0.actor_name', 'Sal Suspended');
});

it('resolves every actor name in one user_profiles query, whatever the page size', function (): void {
    [$user, , $access] = signIn();

    $plan = Plan::factory()->create(['owner_id' => $user->id]);
    member($plan, $user, MemberRole::Owner, '2026-01-01 00:00:00');

    // 25 rows and 25 DISTINCT actors, which is the page an N+1 would cost 25 queries for. The
    // count below is the only thing that can tell a batch load from an N+1: both return
    // byte-identical JSON, so no status and no body assertion can see the difference.
    $names = [];

    for ($i = 0; $i < 25; $i++) {
        $actor = User::factory()->hasProfile(['display_name' => "Actor {$i}"])->create();
        $names[] = "Actor {$i}";

        PlanActivity::factory()->create([
            'plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'activity_type' => 'member.joined',
            'subject_type' => 'User',
            'subject_id' => $actor->id,
            'created_at' => '2026-02-01 10:00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        ]);
    }

    // Registered after the seeding and after signIn(), so the log holds the request's queries
    // and nothing else.
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = withToken($access)->getJson("/api/v1/plans/{$plan->id}/activity?limit=25")
        ->assertOk()
        ->assertJsonCount(25, 'data');

    // A plain string-typed closure parameter, not mixed + is_string(): QueryExecuted::$sql is
    // itself declared `string`, so PHPStan infers array<int, string> for $queries even though
    // it is built through a by-reference closure, and a redundant is_string() guard here is
    // itself a level-8 error ("will always evaluate to true") rather than the fix for one.
    $profileQueries = array_values(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'user_profiles'),
    ));

    expect($profileQueries)->toHaveCount(1);

    // And the names are really resolved, so "one query" cannot be satisfied by a query that
    // fetches nothing: every row carries a name, and the set of names is the set seeded.
    $rendered = array_map(
        static fn (mixed $row): mixed => is_array($row) ? $row['actor_name'] ?? null : null,
        (array) $response->json('data'),
    );

    sort($rendered);
    sort($names);

    expect($rendered)->toBe($names);
});
