<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanInvite;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

/**
 * Spec §17.2 suite 3 and spec §17.3's precise-body IDOR rows, in one test, for every Plan route
 * that carries a second path parameter.
 *
 * The property: findVisibleTo() runs FIRST in every use case, so the vague "Plan not found."
 * body is what a non-member sees whatever the second parameter names, and the precise
 * "That person is not a member of this Plan."/"That invite is no longer available." body is
 * only ever reachable by a caller who has already proved they can see the Plan. A use case that
 * resolved the subresource first would turn that precise sentence into a Plan-existence oracle
 * for anyone with a guessed Plan id - §8.1's uniform 404 defeated one layer in, with every
 * individual route still answering 404 and every status-only assertion still green.
 *
 * Both callers in one test, deliberately: the ordering is a statement about the DIFFERENCE
 * between them. Each half alone is satisfiable by an implementation that answers the vague body
 * to everybody, and the half that would catch that is the other one. It is also what makes
 * forgetAuthenticatedUser() load-bearing here - two tokens alternate inside the loop below, and
 * Sanctum's guard caches the resolved user (see forgetAuthenticatedUser()'s own docblock in
 * tests/Pest.php for the measured probe).
 *
 * Derived off substr_count($path, '{') >= 2, so a route that adds a second parameter without an
 * ordering row fails this file rather than being skipped by it.
 *
 * KNOWN OPEN GAP, deliberately not fixed here: no variant below ever names the actor's own id,
 * so every DELETE /plans/{plan}/members/{user} request this file makes takes the RemoveMember
 * branch, never LeavePlan (the controller dispatches on strcasecmp($user, $actor->id)).
 * LeavePlan's own findVisibleTo()-first ordering therefore has zero HTTP coverage in this suite.
 * A self variant would also need its own expectation arm - the owner naming themselves reaches
 * LeavePlan, where canLeave(Owner) is false, so the owner gets 403 with OwnerCannotLeave's body,
 * not a 2xx and not a 404 - so adding it is a deliberate extension of this file, not a drop-in
 * row, and is left to whichever task picks the gap up.
 *
 * WHICH ROWS PROVE THE WRITE, NOT ONLY THE RESPONSE: every row's body is checked, but a body
 * comparison can only prove what produced that body. On `{user}`-from-another-Plan, the PATCH
 * route's 404 is produced by findActiveMemberView()'s OWN, independently plan_id-scoped re-read
 * - not by the write-side guard (activeMemberQuery()'s shared plan_id predicate, also used by
 * findTransferTarget()) holding - so a body comparison alone cannot tell "the write-side guard
 * held" from "the write-side guard failed but the re-read masked it" on that one row. That is
 * why the `{user}`-from-another-Plan variant additionally reads the target's own plan_members
 * row and the plan_activity table directly, below, for BOTH `{user}` routes (DELETE's write has
 * no such re-read to mask behind, so its body already discriminated correctly - the direct read
 * is added there too, for the same property, rather than only where it was missing). The
 * `{invite}`-from-another-Plan row needs no equivalent: RevokeInvite's write has no later
 * plan-scoped re-read standing in front of its response, so its body already proves the guard
 * (confirmed: removing EloquentInviteRepository::findForPlan()'s plan_id predicate flips that
 * row's response itself to 204, with nothing to mask it). Every other row (this Plan, left,
 * removed, non-uuid, unknown uuid) asserts the body only, because none of them has a
 * guard-vs-readback distinction to lose: the row named either does not exist, is not active, or
 * is shaped wrong, and no write is reachable in any of those cases regardless of which guard
 * runs.
 */

/**
 * Every Plan route with a second path parameter, as [method, path, secondParameterName].
 *
 * The throw is the growth guard. A route with three parameters, or one whose first parameter
 * is not {plan}, is a shape this suite's fixture does not know how to build, and the honest
 * answer is to stop rather than to request it with an undecided expectation.
 *
 * @return list<array{string, string, string}>
 */
function twoParameterRoutes(): array
{
    $rows = [];

    foreach (planRouteTable() as [$method, $path]) {
        if (substr_count($path, '{') < 2) {
            continue;
        }

        preg_match_all('/\{(\w+)\}/', $path, $matches);

        $parameters = $matches[1];

        if (! isset($parameters[0], $parameters[1]) || count($parameters) !== 2 || $parameters[0] !== 'plan') {
            throw new RuntimeException(
                "Expected [{$path}] to carry exactly two path parameters with {plan} first; found ["
                .implode(', ', $parameters).'].'
            );
        }

        $rows[] = [$method, $path, $parameters[1]];
    }

    return $rows;
}

/**
 * The exact body each second path parameter's own not-found exception renders.
 *
 * Keyed by parameter name rather than by route, because the body is a property of the
 * subresource: every route that resolves a {user} answers PlanMemberNotFound, every route that
 * resolves an {invite} answers PlanInviteNotFound. A route whose second parameter is neither
 * fails the completeness check in the suite below by name.
 *
 * @return array<string, string>
 */
function subresourceRefusalBodies(): array
{
    return [
        'user' => '{"message":"That person is not a member of this Plan."}',
        'invite' => '{"message":"That invite is no longer available."}',
    ];
}

/**
 * The second-parameter values this suite probes, for a given parameter name.
 *
 * `a real row of this Plan` is first and is the positive control: the non-member must still get
 * the vague 404, and the owner must SUCCEED. Without it, a fixture whose second parameter named
 * nothing would answer 404 to both callers on every row and the suite would pass with the
 * visibility check deleted.
 *
 * §17.2's "nothing at all" is `an unknown uuid`, not an absent path segment: an absent {user}
 * makes the request /api/v1/plans/<id>/members, which matches a different registered route, and
 * the answer comes from the router rather than from findVisibleTo().
 *
 * @return list<string>
 */
function orderingVariants(string $parameter): array
{
    $common = [
        'a real row of this Plan',
        'a real row of another Plan',
        'a non-uuid',
        'an unknown uuid',
    ];

    // §17.3: a {user} whose plan_members row is `left` or `removed` is 404 PlanMemberNotFound -
    // the row exists, and it does not grant access. {invite} has no analogous state in this
    // suite's variant set.
    return $parameter === 'user'
        ? [...$common, 'a left member of this Plan', 'a removed member of this Plan']
        : $common;
}

/**
 * One active Plan with every second-parameter shape this suite needs seeded against it.
 *
 * A fresh one per variant, because the owner's `a real row of this Plan` request succeeds and
 * mutates - PATCH .../members/{user} changes a role, and DELETE removes or revokes a row - so a
 * shared Plan would make the suite order-dependent.
 *
 * The left and removed members arrive through PlanMemberFactory's own states rather than
 * through member(), which hardcodes MemberStatus::Active and takes no status - and through the
 * factory rather than PlanMember::create(), because PlanMember is $guarded = ['*'] and a direct
 * ::create() throws MassAssignmentException. They are built with User::factory()->hasProfile()
 * rather than userWithPassword() because they never sign in; hasProfile() is not optional
 * decoration, for the reason userWithPassword()'s own docblock in tests/Pest.php records.
 *
 * @return array{Plan, User, PlanInvite, User, User}
 */
function orderingPlan(User $owner): array
{
    [$plan, $activeMember, $invite] = refusalFixture($owner);

    $leftMember = User::factory()->hasProfile()->create();
    PlanMember::factory()->left()->create([
        'plan_id' => $plan->id,
        'user_id' => $leftMember->id,
    ]);

    $removedMember = User::factory()->hasProfile()->create();
    PlanMember::factory()->removed()->create([
        'plan_id' => $plan->id,
        'user_id' => $removedMember->id,
    ]);

    return [$plan, $activeMember, $invite, $leftMember, $removedMember];
}

it('covers every Plan route with a second path parameter', function (): void {
    // Spec §8's two-parameter routes, sorted ascending.
    $expected = [
        'delete /plans/{plan}/invites/{invite}',
        'delete /plans/{plan}/members/{user}',
        'patch /plans/{plan}/members/{user}',
    ];

    $keys = array_map(fn (array $row): string => $row[0].' '.$row[1], twoParameterRoutes());
    sort($keys);

    // Both directions, plus the count that an emptied derivation cannot satisfy. $expected is
    // a literal in this file and $keys is derived from the route table, so adding a route with
    // a second parameter fails here until an ordering row is decided for it.
    expect($keys)->toBe($expected);
    expect($keys)->toHaveCount(3);

    // The variant lists are pinned by value, not merely probed for the positive control. A set
    // with no pin on its membership can be edited - e.g. a duplicate 'a non-uuid' standing in
    // for 'a real row of another Plan' - while every grand total below it stays satisfied,
    // because the totals count entries, not which ones they are.
    expect(orderingVariants('user'))->toBe([
        'a real row of this Plan',
        'a real row of another Plan',
        'a non-uuid',
        'an unknown uuid',
        'a left member of this Plan',
        'a removed member of this Plan',
    ]);
    expect(orderingVariants('invite'))->toBe([
        'a real row of this Plan',
        'a real row of another Plan',
        'a non-uuid',
        'an unknown uuid',
    ]);

    $checked = 0;

    foreach (twoParameterRoutes() as [$method, $path, $parameter]) {
        expect(array_key_exists($parameter, subresourceRefusalBodies()))
            ->toBeTrue("{$method} {$path} has the second path parameter [{$parameter}], which has no body in subresourceRefusalBodies()");

        // Not merely "non-empty": the positive control is the one variant whose absence would
        // make every row below a 404 for both callers, and the whole suite vacuous.
        expect(orderingVariants($parameter))->toContain('a real row of this Plan');

        $checked++;
    }

    expect($checked)->toBe(3);
});

it('answers the vague body to a non-member and the precise body to a member who can see the Plan', function (): void {
    $vague = '{"message":"Plan not found."}';

    $owner = userWithPassword();
    [, , $ownerAccess] = signIn($owner);

    // A verified, signed-in account with no plan_members row anywhere.
    [, $outsiderAccess] = authenticatedUser();

    // A second Plan, so "a real row of another Plan" names rows that genuinely exist.
    // $strangerPlan is kept (not discarded) so the cross-Plan state check below can read the
    // target's own plan_members row back by its real (plan_id, user_id) pair.
    $strangerOwner = userWithPassword();
    [$strangerPlan, $otherMember, $otherInvite] = refusalFixture($strangerOwner);

    $variantsChecked = 0;
    $requestsMade = 0;

    foreach (twoParameterRoutes() as [$method, $path, $parameter]) {
        $key = $method.' '.$path;
        $precise = subresourceRefusalBodies()[$parameter];

        foreach (orderingVariants($parameter) as $variant) {
            [$plan, $activeMember, $invite, $leftMember, $removedMember] = orderingPlan($owner);

            $value = match ($variant) {
                'a real row of this Plan' => $parameter === 'user' ? $activeMember->id : $invite->id,
                'a real row of another Plan' => $parameter === 'user' ? $otherMember->id : $otherInvite->id,
                'a left member of this Plan' => $leftMember->id,
                'a removed member of this Plan' => $removedMember->id,
                'a non-uuid' => 'not-a-uuid',
                'an unknown uuid' => '019a0b7e-7c2a-7f3e-8b10-2f6c4d5e6a7b',
                default => throw new RuntimeException("Unknown ordering variant [{$variant}]."),
            };

            $uri = planRouteUri($path, ['plan' => $plan->id, $parameter => $value]);
            $payload = planRoutePayload($key);

            // T33-1 (round 1 fix): captured BEFORE either request, for the one variant whose
            // body cannot discriminate "the write-side guard held" from "it failed but a
            // downstream read masked it" (see this file's top docblock). Reading the row
            // directly, rather than through the application's own plan-scoped re-read, is what
            // makes the discrimination possible: forceFill()+save() persists before any later
            // read can stand in front of it.
            $crossPlanMemberBefore = $parameter === 'user' && $variant === 'a real row of another Plan'
                ? PlanMember::query()->where('plan_id', $strangerPlan->id)->where('user_id', $otherMember->id)->first()
                : null;

            // The non-member. Byte-exact against the vague body, which subsumes "never the
            // precise body": if the content IS this, it is not the other. A companion
            // ->not->toBe() would be strictly redundant.
            forgetAuthenticatedUser();
            $outsiderResponse = withToken($outsiderAccess)->json(strtoupper($method), $uri, $payload);
            $requestsMade++;

            expect([$outsiderResponse->getStatusCode(), $outsiderResponse->getContent()])
                ->toBe([404, $vague], "{$key} with {$variant} as the {$parameter} must answer the vague PlanNotVisible body to a non-member, never the precise one (§5.2)");

            // The Plan's owner, same route, same second-parameter value.
            forgetAuthenticatedUser();
            $ownerResponse = withToken($ownerAccess)->json(strtoupper($method), $uri, $payload);
            $requestsMade++;

            if ($variant === 'a real row of this Plan') {
                // The positive control. If this fails, the fixture's second parameter is not
                // naming what it claims to, and every vague-body assertion above is passing
                // for the wrong reason.
                expect(in_array($ownerResponse->getStatusCode(), [200, 201, 204], true))
                    ->toBeTrue("{$key} with {$variant} as the {$parameter} must succeed for the Plan's owner, and spec §5.4 says that is 200, 201 or 204 - got {$ownerResponse->getStatusCode()}");
            } else {
                expect([$ownerResponse->getStatusCode(), $ownerResponse->getContent()])
                    ->toBe([404, $precise], "{$key} with {$variant} as the {$parameter} must answer the precise subresource body to the Plan's owner (§5.2)");
            }

            if ($crossPlanMemberBefore !== null) {
                // T33-1: the state assertion the body comparison above cannot make, for the
                // reason stated beside $crossPlanMemberBefore's capture and in this file's top
                // docblock. A fresh read, not the one in memory - RefreshDatabase runs this
                // whole test in one transaction, so a fresh query sees the HTTP requests' own
                // writes, including one the application's own response might not disclose.
                $crossPlanMemberAfter = PlanMember::query()->find($crossPlanMemberBefore->id);

                if ($crossPlanMemberAfter === null) {
                    throw new RuntimeException(
                        "{$key} with {$variant} as the {$parameter}: the cross-Plan target's plan_members row disappeared, which no code path in this repository does."
                    );
                }

                expect([$crossPlanMemberAfter->role, $crossPlanMemberAfter->status])
                    ->toBe([$crossPlanMemberBefore->role, $crossPlanMemberBefore->status], "{$key} with {$variant} as the {$parameter} must leave another Plan's member row untouched - the response body cannot prove this on its own (T33-1)");

                expect(PlanActivity::query()->where('subject_id', $otherMember->id)->count())
                    ->toBe(0, "{$key} with {$variant} as the {$parameter} must write no plan_activity row for a user who is not this Plan's member");
            }

            $variantsChecked++;
        }
    }

    // Two {user} routes at six variants each, plus one {invite} route at four: 12 + 4 = 16
    // variants, each probed by both callers, so 32 requests. A derivation that matched no
    // routes fails here rather than passing green with nothing asserted.
    expect($variantsChecked)->toBe(16);
    expect($requestsMade)->toBe(32);
});
