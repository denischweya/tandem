<?php

declare(strict_types=1);

use App\Domain\Plans\PlanStatus;
use App\Models\Plan;
use App\Models\PlanActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('gates exactly the Plan routes spec 7.5 names, read off the route table', function (): void {
    // Read off Laravel's route table rather than off a hand-written list, for the reason
    // OpenApiContractTest's own helper gives about the document: a route added without a gating
    // decision then fails this assertion instead of being skipped by it. That is the most
    // durable property available here - a test that breaks when the system GROWS, not only when
    // it regresses - and it is what makes spec §4.4's "no Policy classes" decision safe, because
    // the guarantee comes from the gate and not from the presence of a class.
    //
    // uri() is 'api/v1/plans/{plan}', with the prefix. OpenApiContractTest::documentedRoutes()
    // strips it because `servers` carries it in the document; this traversal reads the raw uri,
    // so a filter written against '/plans' would match nothing and the foreach would never run.
    // An empty $gated is NOT caught by the two expectations below on their own:
    // array_diff_key([], $spec75) is [] and array_intersect_key($spec75, []) == [] too, so an
    // empty $gated satisfies both of them having checked nothing - measured by forcing the
    // filter to match no route. That is why there is a third, explicit
    // expect($gated)->not->toBeEmpty() ahead of the completeness check below: the one assertion
    // here whose job is to rule out "the traversal matched nothing" rather than to check what it
    // found.
    //
    // The traversal is inline rather than a helper: a file-scope function in a test file is
    // process-global, and tests/Pest.php already carries 12 helpers while 8 more sit in 5 test
    // files. Nothing else needs this one.
    $gated = [];
    $authenticated = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/plans')) {
            continue;
        }

        // Named, or the key is the uri - which makes an unnamed route show up in the diff
        // below instead of silently colliding on an empty string key.
        $key = $route->getName() ?? '(unnamed) '.$route->uri();

        $middleware = $route->gatherMiddleware();

        $gated[$key] = in_array('verified', $middleware, true);
        $authenticated[$key] = in_array('auth:sanctum', $middleware, true);
    }

    // Spec §7.5's partition for EVERY Plan route under api/v1/plans - not only the ones that
    // exist at this commit, but all eighteen the section will ever carry, one row each below
    // (the previous wording counted today's routes as "six", which Task 21's and this task's
    // routes each falsified in turn: a count of the file's own contents decays silently, so this
    // sentence now counts only the literal immediately below it). A literal rather than
    // a comment: the mutation this guards against is an implementer declaring a later route
    // (say `PATCH /plans/{plan}/members/{user}`) WITHOUT `verified`, then quietly repairing a
    // comment-derived map to agree with whatever they just shipped - which degrades the gate from
    // "spec §7.5's partition is enforced" to "the map agrees with whatever middleware was
    // declared", the implementer writing both sides of the test. Recording every verdict here,
    // now, closes that: a later task can only go green by OVERTURNING a row already committed in
    // this array, which is a visible, deliberate edit to a decision this task made - not by
    // filling in a blank nobody had an opinion on. Each later task's row and its own §7.5 reason
    // is recorded directly in the array below, not merely in the comment above it, so a later
    // task's own dispatch can read its verdict straight off this file rather than re-deriving it.
    $spec75 = [
        'plans.index' => false,            // reading
        'plans.store' => true,              // creates a Plan, an owner membership and a feed row
        'plans.show' => false,             // reading
        'plans.update' => true,             // creates state other members see (a feed row)
        'plans.destroy' => true,           // removes the Plan for every member, unrecoverably
        'plans.archive' => true,           // ends the Plan for every member
        'plans.members.index' => false,    // reading
        'plans.members.update' => true,    // acts on another member: changes their role
        'plans.members.destroy' => false,  // see the note below - the one row middleware cannot express
        'plans.invites.store' => true,     // acts on another person's prospective membership
        'plans.invites.index' => true,     // §10.8: Owner and Admin, not a read exemption
        'plans.invites.destroy' => true,   // revokes another person's invitation
        'plans.join' => false,             // the invite token is the credential, not `verified`
        'plans.activity.index' => false,   // reading
        'plans.messages.index' => false,   // reading
        'plans.messages.store' => true,    // creates state other members see (a chat message)
        'plans.messages.update' => true,   // edits a chat message every other member sees
        'plans.messages.destroy' => true,  // deletes a chat message every other member sees
    ];

    // The note promised above: §7.5's table lists DELETE .../members/{user} in BOTH columns -
    // gated when it removes someone else, ungated when the actor is leaving. Route middleware
    // cannot express that, because EnsureEmailIsVerified sees only the request and the
    // distinction needs the comparison between {user} and the authenticated user. So that route
    // carries no `verified`, and the members section owes the verification check for the
    // remove-someone-else branch - including a decision about which body that refusal carries:
    // EnsureEmailIsVerified's sentence is not reachable from the Application layer, so a bare
    // AuthorizationException there gives "This action is unauthorized." instead. Recorded here
    // because this is where the next person will look, and disclosed as a false negative of the
    // map above: a `false` verdict for this one row does not mean "ungated", only "not
    // expressible by route middleware".
    //
    // Also disclosed: this map says nothing about ORDER relative to ThrottleRequests. A route can
    // carry `verified` and still let an unverified, over-limit caller see 429 instead of 403 -
    // that is residual 17, pinned as its own tripwire below rather than folded in here.

    // Anti-vacuity: neither expectation below would catch an empty $gated on its own (both
    // array_diff_key and array_intersect_key against [] are themselves [] or == []), so this is
    // the one assertion whose entire job is ruling out "the prefix filter matched no route" -
    // the one way the two checks below could pass having checked nothing.
    expect($gated)->not->toBeEmpty(
        'the prefix filter matched no route under api/v1/plans, so this case checked nothing',
    );

    // Completeness: every route this traversal actually finds must have a recorded verdict
    // above. This is the half that reds when the system GROWS a Plan route nobody made a §7.5
    // decision about.
    expect(array_diff_key($gated, $spec75))->toBe(
        [],
        'a route under api/v1/plans with no §7.5 verdict recorded in $spec75',
    );

    // Correctness: every route that exists today must match its recorded verdict. A second,
    // separate expectation rather than folding this into the one above - a single combined
    // assertion would abort before this property was ever evaluated, and the two failure
    // messages tell a later reader which half broke.
    expect($gated)->toEqual(
        array_intersect_key($spec75, $gated),
        'spec §7.5: `verified` gates every Plan route that acts on another member or creates state other members see, and gates no read, no join and no leave',
    );

    // Every Plan route is authenticated. Separate again, so the failure says which of the three
    // properties broke. Unlike $gated above, this is a universal invariant rather than a per-route
    // decision - the correct value for any future row is always `true` - so a hand-written map of
    // the routes that exist today is enough: a later task's new authenticated route still forces
    // that task to add its key here (the exact-map comparison fails on key count alone), and there
    // is no verdict to get wrong once it does. (No count in that sentence: it read "today's eight"
    // and this task's row made it nine, which is what an unasserted count does when the map below
    // is the thing that grows.)
    //
    // plans.members.destroy carries no per-route `auth:sanctum` of its own - the enclosing group
    // in routes/api/v1/plans.php applies it, and gatherMiddleware() is what this traversal reads,
    // so a group-applied middleware counts here exactly as a route-applied one does.
    expect($authenticated)->toEqual([
        'plans.index' => true,
        'plans.store' => true,
        'plans.show' => true,
        'plans.update' => true,
        'plans.destroy' => true,
        'plans.archive' => true,
        'plans.members.index' => true,
        'plans.members.update' => true,
        'plans.members.destroy' => true,
        'plans.invites.store' => true,
        'plans.invites.index' => true,
        'plans.invites.destroy' => true,
        'plans.join' => true,
        'plans.activity.index' => true,
        'plans.messages.index' => true,
        'plans.messages.store' => true,
        'plans.messages.update' => true,
        'plans.messages.destroy' => true,
    ], 'every Plan route resolves an actor, including join - plan_invites.accepted_by records who accepted');
});

it('lets an unverified active member read', function (): void {
    $owner = userWithPassword(attributes: ['email_verified_at' => null]);
    $plan = ownedPlan($owner);

    [, , $access] = signIn($owner);

    // If joining were exempt and reading were not, a newly-joined unverified user would be
    // refused on the Plan they had just joined - which is worse than either extreme, and is the
    // argument spec §7.5 gives for the line falling here.
    withToken($access)->getJson('/api/v1/plans')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    withToken($access)->getJson("/api/v1/plans/{$plan->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $plan->id);
});

it('refuses all four Plan writes for an unverified owner, and writes nothing', function (): void {
    // The actor is the OWNER, deliberately: an unverified plain member would be refused by the
    // role check too, and the test would pass without the gate existing. The owner is the one
    // caller who would otherwise succeed on all four, so a 403 here can only be the gate.
    $owner = userWithPassword(attributes: ['email_verified_at' => null]);
    $plan = ownedPlan($owner);

    [, , $access] = signIn($owner);

    $refusals = [
        'create' => fn () => withToken($access)->postJson('/api/v1/plans', [
            'title' => 'Dinner',
            'timezone' => 'Europe/London',
        ]),
        'edit' => fn () => withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => 'Renamed']),
        'archive' => fn () => withToken($access)->postJson("/api/v1/plans/{$plan->id}/archive"),
        'delete' => fn () => withToken($access)->deleteJson("/api/v1/plans/{$plan->id}"),
    ];

    foreach ($refusals as $label => $call) {
        $response = $call();

        // expect()->toBe() rather than assertForbidden(): assertForbidden() carries no failure
        // message, and this line is shared by all four iterations - a red here would otherwise
        // read only "Expected ... [403] but received ..." with the row unnamed, because $label
        // is reached only on the sentence assertion below, which runs only once the status has
        // already passed.
        expect($response->status())->toBe(
            403,
            "the {$label} route must be refused (403) by the verified gate",
        );

        // EnsureEmailIsVerified's own sentence, from abort(403, 'Your email address is not
        // verified.') - NOT AuthorizationException's 'This action is unauthorized.', which spec
        // §5.4's table states for this row and which is the body a role failure carries. A
        // client distinguishes the two by the sentence, or by data.email_verified from GET /me,
        // which it already holds.
        expect($response->json('message'))->toBe(
            'Your email address is not verified.',
            "the {$label} route must be refused by the verified gate, with the gate's own sentence",
        );
    }

    // The Plan is untouched and no second one exists: the gate runs before the controller, so
    // nothing reached CreatePlan, UpdatePlan, ArchivePlan or DeletePlan.
    expect(Plan::query()->count())->toBe(1, 'no Plan may be created by a refused request');

    $fresh = Plan::query()->sole();

    expect($fresh->id)->toBe($plan->id, 'and the existing Plan must not have been deleted');
    expect($fresh->title)->toBe($plan->title);
    expect($fresh->status)->toBe(PlanStatus::Active->value);
    expect($fresh->archived_at)->toBeNull();
    expect(PlanActivity::query()->count())->toBe(0);
});

it('lets a verified owner through all four', function (): void {
    // The other half of the gate, and the half that makes the test above mean something: a file
    // that only ever asserts 403 would also pass if `verified` were replaced by a middleware
    // that refused everyone.
    $owner = userWithPassword();
    $plan = ownedPlan($owner);

    [, , $access] = signIn($owner);

    withToken($access)->postJson('/api/v1/plans', ['title' => 'A second Plan', 'timezone' => 'Europe/London'])
        ->assertCreated();

    // In this order on purpose: PATCH before archive, because PATCH on an archived Plan is a
    // 409, and DELETE last because it removes the row the other two need.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}", ['title' => 'Renamed'])->assertOk();
    withToken($access)->postJson("/api/v1/plans/{$plan->id}/archive")->assertOk();
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}")->assertNoContent();
});

it('refuses an unverified write for a non-JSON client too, as a 500 - a recorded defect', function (): void {
    $owner = userWithPassword(attributes: ['email_verified_at' => null]);

    [, , $access] = signIn($owner);

    // Accept: */* is curl's default and the shape of every non-browser client that forgets the
    // header. EnsureEmailIsVerified reads $request->expectsJson() DIRECTLY, and expectsJson() is
    // a property of the ACCEPT HEADER - not of bootstrap/app.php's shouldRenderJsonWhen callback,
    // which governs only the FORMAT the handler renders. That is why the body below is
    // JSON-shaped while the status is not 403: with a non-JSON Accept the middleware takes its
    // redirect branch, and URL::route('verification.notice') throws RouteNotFoundException
    // because routes/web.php is deliberately empty. Measured by probe: expectsJson() is false
    // for */*, false for no Accept header, false for text/html, and true only for
    // application/json, and Route::has('verification.notice') is false.
    //
    // Asserted rather than left undiscovered, and asserted as a TRIPWIRE: the refusal itself
    // still holds - nothing is written, which is the security-relevant half - but the status is
    // wrong, and this case goes red the day someone fixes it. Whoever does: the fix is a
    // force-JSON middleware on the api group or a named verification.notice route, both outside
    // Wave 2's scope, and then this assertion becomes assertForbidden() with
    // {"message":"Your email address is not verified."}.
    //
    // The alias registration for `verified` in bootstrap/app.php carries a comment claiming
    // "every request under api/* is forced to JSON by the shouldRenderJsonWhen callback below" -
    // that claim is factually wrong for exactly this reason: shouldRenderJsonWhen governs only
    // how the HANDLER renders an exception it decides to render, never what expectsJson() itself
    // returns, and EnsureEmailIsVerified never consults shouldRenderJsonWhen at all.
    withToken($access)
        ->post('/api/v1/plans', ['title' => 'Dinner', 'timezone' => 'Europe/London'], ['Accept' => '*/*'])
        ->assertStatus(500);

    expect(Plan::query()->count())
        ->toBe(0, 'the gate must refuse before CreatePlan runs, whatever the Accept header says');
});

it('spends a plans-create rate-limit token on every unverified refusal, so the 403 is not what an over-limit caller eventually sees - residual 17', function (): void {
    // Residual 17, owned by this task. Measured (routes/api/v1/plans.php's own comment above
    // plans.store states the same sorted order): EnsureEmailIsVerified is absent from Laravel's
    // $middlewarePriority (bootstrap/app.php never calls priority(), and
    // Middleware::getMiddlewarePriority() returns [] for this app), so it sorts LAST in the
    // Kernel's own 11-entry default list, while ThrottleRequests sorts well ahead of it -
    // and ThrottleRequests::handleRequest() calls hit() before passing the request on. So every
    // one of an unverified caller's 403s still spends a plans-create token, and the thirty-first
    // spent token answers 429, not 403: the published 403
    // {"message":"Your email address is not verified."} is not what an over-limit unverified
    // client actually sees on its last attempt. The only lever that would change this -
    // an explicit $middlewarePriority override putting EnsureEmailIsVerified ahead of
    // ThrottleRequests - is outside Wave 2's scope, which is why this is a tripwire and not a fix.
    //
    // Reuses CreatePlanTest's own mechanism for the same limiter ("limits one user to thirty an
    // hour...") rather than inventing one or pre-filling the cache key directly: thirty real
    // requests, so the discriminating observation - WHICH STATUS arrives on each one - is
    // measured by sending the request through the real middleware stack, not asserted about it
    // from outside. One it() block, for the same reason that test gives: phpunit.xml pins
    // CACHE_STORE=array and the application rebuilds per test, so the budget cannot be exhausted
    // across two blocks.
    $owner = userWithPassword(attributes: ['email_verified_at' => null]);

    [, , $access] = signIn($owner);

    $first = withToken($access)->postJson('/api/v1/plans', [
        'title' => 'Plan 1',
        'timezone' => 'Europe/London',
    ]);

    expect($first->status())->toBe(
        403,
        'request 1 of thirty must still be refused by the gate, not yet by the limiter',
    );

    // The sentence is checked once, against this first refusal, not on every one of the thirty:
    // all thirty share the same code path (EnsureEmailIsVerified), so the status check alone -
    // asserted on every request below, each naming its own number - already tells any iteration
    // that takes a DIFFERENT path apart from one that does not. Repeating the body comparison
    // thirty times would cost thirty assertions to re-confirm a fact this one already settles.
    expect($first->json('message'))->toBe('Your email address is not verified.');

    for ($i = 2; $i <= 30; $i++) {
        $response = withToken($access)->postJson('/api/v1/plans', [
            'title' => "Plan {$i}",
            'timezone' => 'Europe/London',
        ]);

        // expect()->toBe() rather than assertForbidden(), so a red names WHICH of the thirty
        // requests broke instead of reading only "Expected ... [403] but received ...": with no
        // index, a status red here could not say whether the boundary had moved from 31 down to
        // some earlier request.
        expect($response->status())->toBe(
            403,
            "request {$i} of thirty must still be refused by the gate, not yet by the limiter",
        );
    }

    // The measured boundary this test exists to pin: the thirty-first spent token is a 429, not
    // the 403 every one of the thirty before it answered.
    withToken($access)->postJson('/api/v1/plans', [
        'title' => 'Plan 31',
        'timezone' => 'Europe/London',
    ])->assertStatus(429);

    expect(Plan::query()->count())
        ->toBe(0, 'all thirty-one requests were refused before CreatePlan ever ran - by the gate or by the limiter');
});
