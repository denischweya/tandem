<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Models\Plan;
use App\Models\PlanInvite;
use App\Models\PlanMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

/**
 * Spec §17.2 suite 2: spec §3.6's matrix, one entry per Plan route, enumerated off Laravel's
 * route table so a route added without an authorization row fails this file instead of being
 * skipped by it.
 *
 * This is the gate spec §4.4 leans on. Measured: app/Policies does not exist and `Gate::`
 * appears nowhere under app/, so there is no Policy class and no ability registration whose
 * absence anything else would notice. The guarantee is this test.
 *
 * It is also the 403 half of spec §8.1. A suite that only asserted the non-member 404 would
 * pass for an implementation that answered 404 to every refusal - which would be a different
 * API from the one §5.2 specifies, and a worse one: a member who can plainly see their own
 * Plan being told "Plan not found" is the refusal blueprint §135 exists to prevent.
 *
 * Completeness is checked at THREE grains, because the coarsest one alone is not a gate:
 *
 *   1. the route key, against planRouteKeys() - a Plan route with no entry fails;
 *   2. the per-route declared case count, `expectedCases` - a case dropped from a route's list
 *      fails by name instead of being absorbed into the suite's grand total;
 *   3. the authorization pair, derived from MemberRole::cases(): every (actor role, target
 *      role) pair on each route whose predicate takes two roles, every (actor role, invited
 *      role) pair on POST /plans/{plan}/invites, and a target=self verdict per role on the one
 *      route that dispatches on whether {user} is the actor. A required case NOBODY EVER WROTE
 *      fails here, which neither 1 nor 2 can see, because a key comparison ignores a list's
 *      contents and a declared count agrees with whatever the author typed.
 *
 * Grain 3 exists because grain 1 failed in practice: an earlier draft of this file carried
 * three cases under 'delete /plans/{plan}/members/{user}' and silently omitted §17.3's
 * "Anyone removing the owner, or the owner leaving" row, with the key comparison green.
 *
 * Both 403 bodies are compared byte for byte, because they are different bytes. A role failure
 * is a bare AuthorizationException and reaches the client through Laravel's own handler, which
 * PRETTY-PRINTS (Handler::prepareJsonResponse()). The owner-leave refusal is OwnerCannotLeave
 * rendered by a closure in bootstrap/app.php, which encodes COMPACTLY. Status alone cannot
 * distinguish them, and neither can assertJsonPath.
 */

/**
 * Spec §3.6, as this suite drives it: route key -> the number of authorizations that route
 * carries and the list of them, each with a target, a request body, and a verdict for every
 * role.
 *
 * A "minimum role" column could not express this matrix. DELETE /plans/{plan}/members/{user}
 * is one route and two authorizations (§9.2's leave versus §3.3's remove); an admin may remove
 * a Member but not another Admin; an admin may mint a member-role invite but not an
 * admin-role one. Each is its own case, which is how §17.3's four 403 IDOR rows become part
 * of a derived matrix rather than four sampled tests.
 *
 * Verdicts: 'allowed' (200, 201 or 204 - spec §5.4), 'unauthorized' (403 with Laravel's own
 * pretty-printed body), 'owner-cannot-leave' (403 with OwnerCannotLeave's compact body, §9.2).
 * Targets: 'self' (the actor, whose role is therefore the actor's role), 'owner' (the Plan's
 * owner), 'admin'/'member' (a second Admin/Member of the same Plan, never the actor), 'none'
 * (the route has no {user} parameter to substitute).
 *
 * expectedCases is the count this route declares, asserted against the length of its own list.
 * It is a floor, not a derivation: it catches a case removed from a list, and it cannot catch
 * a case never written. The pair coverage in the first test below is what catches that, for the
 * three routes whose predicate takes two roles.
 *
 * The three empty lists are decisions, not omissions, and are written out so that the
 * completeness check still requires a key for them.
 *
 * @return array<string, array{expectedCases: int, cases: list<array{case: string, target: string, payload: array<string, mixed>, expect: array<string, string>}>}>
 */
function planRoleMatrix(): array
{
    return [
        // §3.6: "the result set IS the authorization" - this route lists the actor's own Plans,
        // so there is no role to be below. Covered by the resources section's list tests.
        'get /plans' => ['expectedCases' => 0, 'cases' => []],
        // §3.6: any verified user. The `verified` gate is the Plans section's Task B.
        'post /plans' => ['expectedCases' => 0, 'cases' => []],
        // §3.6: "the invite token is the credential" (§10.7). Every failure answers the one
        // InviteNotUsable body, covered by the invitations section.
        'post /plans/{plan}/join' => ['expectedCases' => 0, 'cases' => []],

        'get /plans/{plan}' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'view the Plan',
                'target' => 'none',
                'payload' => [],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'allowed'],
            ]],
        ],
        'patch /plans/{plan}' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'edit the Plan',
                'target' => 'none',
                'payload' => ['title' => 'Renamed by the role matrix'],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'unauthorized'],
            ]],
        ],
        'delete /plans/{plan}' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'delete the Plan',
                'target' => 'none',
                'payload' => [],
                'expect' => ['owner' => 'allowed', 'admin' => 'unauthorized', 'member' => 'unauthorized'],
            ]],
        ],
        'post /plans/{plan}/archive' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'archive the Plan',
                'target' => 'none',
                'payload' => [],
                'expect' => ['owner' => 'allowed', 'admin' => 'unauthorized', 'member' => 'unauthorized'],
            ]],
        ],
        'get /plans/{plan}/members' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'list the members',
                'target' => 'none',
                'payload' => [],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'allowed'],
            ]],
        ],

        // Three cases, one per target role, because canChangeRole(actor, target, to) decides on
        // the (actor, target) pair: three cases x three actor roles is all nine pairs. `to` is
        // not a fourth dimension here - tests/Unit/Domain/Plans/PlanPermissionsTest.php drives
        // canChangeRole over all 27 (actor, target, to) triples and pins it `to`-invariant.
        'patch /plans/{plan}/members/{user}' => [
            'expectedCases' => 3,
            'cases' => [
                [
                    // §3.2 and §17.3: "an admin promoting anyone" is 403. `assign admins` is
                    // the owner's capability alone.
                    'case' => 'promote a member',
                    'target' => 'member',
                    'payload' => ['role' => 'admin'],
                    'expect' => ['owner' => 'allowed', 'admin' => 'unauthorized', 'member' => 'unauthorized'],
                ],
                [
                    // §8.2: the owner may demote an Admin to Member. An admin may not, which is
                    // §3.2 from the other side - an admin who could demote another admin would
                    // hold the appointment power the matrix reserves to the owner.
                    'case' => 'demote an admin',
                    'target' => 'admin',
                    'payload' => ['role' => 'member'],
                    'expect' => ['owner' => 'allowed', 'admin' => 'unauthorized', 'member' => 'unauthorized'],
                ],
                [
                    // §8.2's second refusal, verbatim: "$target === Owner - the owner's own
                    // role is never edited directly. Demoting the owner to member would leave
                    // plans.owner_id pointing at a non-owner, and promoting them is a no-op."
                    // That is canChangeRole's `$target !== MemberRole::Owner`, which is false
                    // for every actor including the owner, so all three verdicts are the
                    // generic 403. An ownership transfer is `role=owner` on a NON-owner
                    // target (§9.3) and belongs to the members section's own tests, not here.
                    'case' => 'change the role of the owner',
                    'target' => 'owner',
                    'payload' => ['role' => 'admin'],
                    'expect' => ['owner' => 'unauthorized', 'admin' => 'unauthorized', 'member' => 'unauthorized'],
                ],
            ],
        ],

        // Four cases: one per target role, plus 'leave', because this URI is two use cases and
        // the controller tells them apart by whether {user} is the actor. Three cases would
        // cover all nine (actor, target role) pairs canRemoveMember distinguishes, but 'leave'
        // is a different code path with a different answer for the same (Admin, Admin) and
        // (Member, Member) pairs - an admin may always leave and may never remove another
        // admin - so the self case is not a duplicate of 'remove an admin'.
        'delete /plans/{plan}/members/{user}' => [
            'expectedCases' => 4,
            'cases' => [
                [
                    // §9.2: leaving is always allowed, except for the owner, who gets 403 with
                    // an actionable message rather than the generic one. strcasecmp($user,
                    // $actor->id) === 0 in PlanMemberController::destroy() is what routes this
                    // to LeavePlan, where canLeave(Owner) is false.
                    //
                    // Disclosed false negative ([[A2]]): this case's admin/member rows dispatch
                    // to LeavePlan::execute(), and the 'remove a member'/'remove an admin' cases
                    // below dispatch to RemoveMember::execute() - but both use cases end in an
                    // identical `response()->noContent()` (204, no body). This matrix's verdicts
                    // are status-and-body only, so an 'allowed' row here cannot tell them apart.
                    // What actually distinguishes them is the resulting plan_members row:
                    // LeavePlan writes status=left and leaves role untouched; RemoveMember
                    // writes status=removed and demotes role to member. That assertion belongs
                    // to tests/Feature/Plans/LeaveAndRemoveMemberTest.php, not this file.
                    'case' => 'leave',
                    'target' => 'self',
                    'payload' => [],
                    'expect' => ['owner' => 'owner-cannot-leave', 'admin' => 'allowed', 'member' => 'allowed'],
                ],
                [
                    'case' => 'remove a member',
                    'target' => 'member',
                    'payload' => [],
                    'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'unauthorized'],
                ],
                [
                    // §3.3 and §17.3: "an admin removing another admin" is 403.
                    'case' => 'remove an admin',
                    'target' => 'admin',
                    'payload' => [],
                    'expect' => ['owner' => 'allowed', 'admin' => 'unauthorized', 'member' => 'unauthorized'],
                ],
                [
                    // §17.3's fourth 403 row: "Anyone removing the owner, or the owner
                    // leaving | 403 with the actionable message (§9.2)". The two halves of
                    // that row do NOT get the same body, and the row is the outlier rather
                    // than this matrix: §5's exception table names OwnerCannotLeave's raiser as
                    // "LeavePlan (§9.2)" and nothing else, §5.4's 403 row says the generic body
                    // applies "except the owner-LEAVE refusal", and §9.2 calls itself "the one
                    // refusal with an exit" - the three exits it lists are the owner's alone.
                    //
                    // The owner naming themselves gets the actionable body: destroy() takes the
                    // LeavePlan branch (strcasecmp($user, $actor->id) === 0), canLeave(Owner)
                    // is false, OwnerCannotLeave renders compactly. Same verdict as the 'leave'
                    // case above, because actor = target = owner IS self-removal.
                    //
                    // An admin or a member naming the owner does not: destroy() takes the
                    // RemoveMember branch, canRemoveMember(Admin, Owner) and
                    // canRemoveMember(Member, Owner) are both false, and RemoveMember::execute()
                    // answers that with a bare `throw new AuthorizationException` - the generic
                    // pretty-printed body, as §8.3's "Other - RemoveMember" paragraph says.
                    // "Transfer ownership or archive this Plan before leaving it" is advice only
                    // the owner can act on, so the generic body is also the better answer here.
                    // Logged as a spec defect against §17.3's row rather than relaxed. If that
                    // row is ever read strictly, the change is RemoveMember throwing
                    // OwnerCannotLeave for a target whose role is Owner, and these two verdicts
                    // become 'owner-cannot-leave'.
                    'case' => 'remove the owner',
                    'target' => 'owner',
                    'payload' => [],
                    'expect' => ['owner' => 'owner-cannot-leave', 'admin' => 'unauthorized', 'member' => 'unauthorized'],
                ],
            ],
        ],

        // Two cases, one per invitable role, because canInviteWithRole(actor, invited) decides
        // on that pair and `invited` has exactly two reachable values:
        // InvitePlanMemberRequest's rule is in:admin,member, so role=owner is a 422 that never
        // reaches the predicate. Two cases x three actor roles is all six reachable pairs.
        'post /plans/{plan}/invites' => [
            'expectedCases' => 2,
            'cases' => [
                [
                    'case' => 'invite a member',
                    'target' => 'none',
                    'payload' => ['email' => 'invited-member@example.test', 'role' => 'member'],
                    'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'unauthorized'],
                ],
                [
                    // §10.4 and §17.3: "an admin minting an admin-role invite" is 403. Only an
                    // owner may hand out the Admin role, by invite as well as by promotion.
                    'case' => 'invite an admin',
                    'target' => 'none',
                    'payload' => ['email' => 'invited-admin@example.test', 'role' => 'admin'],
                    'expect' => ['owner' => 'allowed', 'admin' => 'unauthorized', 'member' => 'unauthorized'],
                ],
            ],
        ],

        'get /plans/{plan}/invites' => [
            'expectedCases' => 1,
            'cases' => [[
                // §3.6: owner-and-admin rather than any member, because an invite list carries
                // email addresses - personal data about people who are not members (blueprint
                // §126).
                'case' => 'list the invites',
                'target' => 'none',
                'payload' => [],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'unauthorized'],
            ]],
        ],
        'delete /plans/{plan}/invites/{invite}' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'revoke an invite',
                'target' => 'none',
                'payload' => [],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'unauthorized'],
            ]],
        ],
        'get /plans/{plan}/activity' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'read the feed',
                'target' => 'none',
                'payload' => [],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'allowed'],
            ]],
        ],

        // The chat feature's two message routes. SendMessage/ListPlanMessages carry no
        // PlanPermissions::allows() check of their own - findVisibleTo()'s membership
        // resolution IS the authorization, the same reasoning get /plans/{plan}/activity
        // above already gives for its sibling feed - so every role that can see the Plan may
        // read or send.
        'get /plans/{plan}/messages' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'read the messages',
                'target' => 'none',
                'payload' => [],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'allowed'],
            ]],
        ],
        'post /plans/{plan}/messages' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'send a message',
                'target' => 'none',
                'payload' => ['body' => 'Hello from the role matrix'],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'allowed'],
            ]],
        ],

        // Author-only, not role-based: NotMessageAuthor's check is
        // $message->userId !== $actor->getAuthIdentifier(), which no MemberRole enters - an
        // owner editing or deleting another member's message is refused exactly as that other
        // member's fellow member would be, and the Plan's own owner gets no special standing
        // over a message they did not write. §3.6's role matrix has nothing to say about a
        // check that is the same verdict for every role, the same reasoning 'get /plans',
        // 'post /plans' and 'post /plans/{plan}/join' above give for their own empty lists -
        // this is a fourth case of "no role to be below", not a gap in this file's coverage.
        // The actor-vs-author distinction these two routes DO need is covered by
        // tests/Feature/Chat/EditMessageTest.php and tests/Feature/Chat/DeleteMessageTest.php.
        'patch /plans/{plan}/messages/{message}' => ['expectedCases' => 0, 'cases' => []],
        'delete /plans/{plan}/messages/{message}' => ['expectedCases' => 0, 'cases' => []],

        // The chat feature's two reaction routes. Per the design (unlike the author-only
        // edit/delete pair just above), reacting carries no authorship check at all - any
        // active member may add or remove their OWN reaction to or from any message in the
        // Plan, regardless of who sent it and regardless of the reactor's role. AddReaction's
        // and RemoveReaction's own docblocks give the same reasoning
        // 'post /plans/{plan}/messages' above gives for sending: findVisibleTo()'s own
        // membership resolution IS the authorization.
        'post /plans/{plan}/messages/{message}/reactions' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'react to a message',
                'target' => 'none',
                'payload' => ['reaction' => '👍'],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'allowed'],
            ]],
        ],
        'delete /plans/{plan}/messages/{message}/reactions/{reaction}' => [
            'expectedCases' => 1,
            'cases' => [[
                'case' => 'remove a reaction',
                'target' => 'none',
                'payload' => [],
                'expect' => ['owner' => 'allowed', 'admin' => 'allowed', 'member' => 'allowed'],
            ]],
        ],
    ];
}

/**
 * One active Plan with the owner and four other fixture users seeded as members, plus one
 * outstanding invite and one chat message authored by the owner.
 *
 * A fresh Plan per (case, role) pair, because an `allowed` verdict mutates state: DELETE
 * /plans/{plan} answers 204 and takes the Plan with it, POST /plans/{plan}/archive leaves a
 * Plan that answers 409 to every write, and PATCH /plans/{plan}/members/{user} rewrites a role.
 * The five users are created once by the caller and re-membered into each fresh Plan, so the
 * suite needs three logins rather than one per request.
 *
 * joined_at is explicit and ascending, because §9.3's successor rule is "longest-standing
 * remaining member by created_at ASC, id ASC" and a fixture with ties is a fixture whose
 * outcome depends on insertion order. ownedPlan() has already written the owner's own active
 * row at 2026-01-01T00:00:00+00:00.
 *
 * $message exists for the chat feature's two reaction routes, which this matrix opens to every
 * role (see planRoleMatrix()'s own comment beside them) and which therefore need a real
 * {message} to substitute - who authored it does not matter, because reacting carries no
 * authorship check (AddReaction's and RemoveReaction's own docblocks).
 *
 * @return array{Plan, PlanInvite, PlanMessage}
 */
function roleMatrixPlan(User $owner, User $admin, User $planMember, User $targetAdmin, User $targetMember): array
{
    $plan = ownedPlan($owner);

    member($plan, $admin, MemberRole::Admin, '2026-01-02T00:00:00+00:00');
    member($plan, $planMember, MemberRole::Member, '2026-01-03T00:00:00+00:00');
    member($plan, $targetAdmin, MemberRole::Admin, '2026-01-04T00:00:00+00:00');
    member($plan, $targetMember, MemberRole::Member, '2026-01-05T00:00:00+00:00');

    // Field-by-field through `new PlanMessage`, not a factory: PlanMessage is $guarded = ['*']
    // and this feature carries no PlanMessageFactory, the same reasoning refusalFixture()'s
    // own comment in tests/Pest.php gives.
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $owner->id;
    $message->body = 'A message the role matrix probes.';
    $message->save();

    $invite = PlanInvite::factory()->create([
        'plan_id' => $plan->id,
        'invited_by' => $owner->id,
    ]);

    return [$plan, $invite, $message];
}

it('covers every Plan route in the role matrix', function (): void {
    $keys = array_keys(planRoleMatrix());
    sort($keys);

    $routes = planRouteKeys();

    // Grain 1, both directions, as two named differences rather than one fourteen-element
    // array comparison: a Plan route with no matrix entry fails, and a matrix entry for a route
    // that no longer exists fails, and the failure names the route instead of printing a diff
    // in which one missing element rewrites nine lines. planRouteKeys() is derived from the
    // route table and $keys comes from a literal in this file, so the two cannot be emptied
    // together - which is the one way this comparison could otherwise pass having checked
    // nothing.
    $unrowed = array_values(array_diff($routes, $keys));
    $stale = array_values(array_diff($keys, $routes));

    expect($unrowed)->toBeEmpty('Plan routes with no planRoleMatrix() entry: '.implode(', ', $unrowed));
    expect($stale)->toBeEmpty('planRoleMatrix() entries naming no Plan route: '.implode(', ', $stale));
    expect($keys)->toHaveCount(20);

    $cases = 0;

    foreach (planRoleMatrix() as $key => $entry) {
        // Grain 2. A case dropped from this route's list fails here, naming the route, rather
        // than being absorbed into the $cases total at the end of this test.
        expect($entry['cases'])->toHaveCount(
            $entry['expectedCases'],
            "{$key} must carry exactly the {$entry['expectedCases']} authorization cases it declares",
        );

        foreach ($entry['cases'] as $case) {
            $roles = array_keys($case['expect']);
            sort($roles);

            // A role silently missing from a case would be unchecked by the suite below and
            // nothing else would say so.
            expect($roles)->toBe(['admin', 'member', 'owner'], "{$key} [{$case['case']}] must state a verdict for all three roles");

            foreach ($case['expect'] as $role => $verdict) {
                expect(in_array($verdict, ['allowed', 'unauthorized', 'owner-cannot-leave'], true))
                    ->toBeTrue("{$key} [{$case['case']}] as {$role} has the unknown verdict [{$verdict}]");
            }

            expect(in_array($case['target'], ['self', 'owner', 'admin', 'member', 'none'], true))
                ->toBeTrue("{$key} [{$case['case']}] has the unknown target [{$case['target']}]");

            $cases++;
        }
    }

    // Grain 3, part one: the two routes that name a {user} authorize through
    // PlanPermissions::canRemoveMember(MemberRole $actor, MemberRole $target) and
    // ::canChangeRole(MemberRole $actor, MemberRole $target, MemberRole $to), each of which
    // decides on the (actor, target) PAIR. So the obligation is every pair, not every route:
    // grain 1 cannot see a case missing from a list whose key is present, and grain 2 agrees
    // with whatever count the author typed beside the route, so neither can catch a required
    // case that was never written. This one can.
    //
    // The pair list is computed from MemberRole::cases() rather than written out. A fourth
    // MemberRole would therefore fail the count assertion below AND every route's coverage at
    // once, which is a decision rather than a silent gap.
    $memberRolePairs = [];

    foreach (MemberRole::cases() as $actorRole) {
        foreach (MemberRole::cases() as $targetRole) {
            $memberRolePairs[] = $actorRole->value.' -> '.$targetRole->value;
        }
    }

    sort($memberRolePairs);

    expect($memberRolePairs)->toHaveCount(9);

    $userRoutes = array_values(array_filter(
        $routes,
        fn (string $key): bool => str_contains($key, '{user}'),
    ));

    // Derived from the route table, compared against a literal: a filter that matched neither
    // route would leave the loop below asserting nothing at all, which is section F2's failure
    // mode and the reason every loop in this section is counted.
    expect($userRoutes)->toHaveCount(2, 'Exactly two Plan routes name a {user}, and a filter that matched neither would make the pair coverage below vacuous');
    expect($userRoutes)->toBe(['delete /plans/{plan}/members/{user}', 'patch /plans/{plan}/members/{user}']);

    foreach ($userRoutes as $key) {
        $entry = planRoleMatrix()[$key]
            ?? throw new RuntimeException("No role-matrix entry for [{$key}], which the key-set assertions above should already have caught.");

        $covered = [];

        foreach ($entry['cases'] as $case) {
            foreach (array_keys($case['expect']) as $actorRole) {
                // 'self' means the target IS the actor, so the target's role is the actor's.
                $covered[] = $actorRole.' -> '.($case['target'] === 'self' ? $actorRole : $case['target']);
            }
        }

        $covered = array_values(array_unique($covered));
        sort($covered);

        $missing = array_values(array_diff($memberRolePairs, $covered));
        $unknown = array_values(array_diff($covered, $memberRolePairs));

        expect($missing)->toBeEmpty("{$key} states no authorization case for these (actor role, target role) pairs: ".implode(', ', $missing));
        expect($unknown)->toBeEmpty("{$key} states an authorization case for these pairs, which name no MemberRole: ".implode(', ', $unknown));
    }

    // Grain 3, part two: POST /plans/{plan}/invites asks two predicates -
    // PlanPermissions::allows($role, Capability::InviteMembers), which decides on the actor's
    // role alone, and ::canInviteWithRole(MemberRole $actor, MemberRole $invited), which
    // decides on the PAIR - so this route's obligation is a pair too, and the invited role is
    // already in the matrix, as the `role` key of each case's payload. Two invitable roles and
    // not three: the FormRequest's rule is in:admin,member, so role=owner is a 422 that never
    // reaches the predicate, which is why this list is a literal pair of values rather than
    // MemberRole::cases().
    $invitePairs = [];

    foreach (MemberRole::cases() as $actorRole) {
        foreach (['admin', 'member'] as $invitedRole) {
            $invitePairs[] = $actorRole->value.' -> '.$invitedRole;
        }
    }

    sort($invitePairs);

    expect($invitePairs)->toHaveCount(6);

    $inviteEntry = planRoleMatrix()['post /plans/{plan}/invites']
        ?? throw new RuntimeException('No role-matrix entry for [post /plans/{plan}/invites], which the key-set assertions above should already have caught.');

    $coveredInvites = [];

    foreach ($inviteEntry['cases'] as $case) {
        $invitedRole = $case['payload']['role'] ?? null;

        if (! is_string($invitedRole)) {
            throw new RuntimeException("post /plans/{plan}/invites [{$case['case']}] has no string `role` in its payload, so the invited role it authorizes cannot be derived.");
        }

        foreach (array_keys($case['expect']) as $actorRole) {
            $coveredInvites[] = $actorRole.' -> '.$invitedRole;
        }
    }

    $coveredInvites = array_values(array_unique($coveredInvites));
    sort($coveredInvites);

    $missingInvites = array_values(array_diff($invitePairs, $coveredInvites));
    $unknownInvites = array_values(array_diff($coveredInvites, $invitePairs));

    expect($missingInvites)->toBeEmpty('post /plans/{plan}/invites states no authorization case for these (actor role, invited role) pairs: '.implode(', ', $missingInvites));
    expect($unknownInvites)->toBeEmpty('post /plans/{plan}/invites states an authorization case for these pairs, which name no invitable role: '.implode(', ', $unknownInvites));

    // Grain 3, part three: the (actor role, target role) pair is not quite the whole
    // obligation on DELETE /plans/{plan}/members/{user}, because that one route carries TWO
    // authorization paths and the pair cannot tell them apart. PlanMemberController::destroy()
    // dispatches on strcasecmp($user, $actor->id) === 0: naming yourself reaches
    // LeavePlan::execute() and PlanPermissions::canLeave(MemberRole $role), a three-role
    // domain of its own, and naming anyone else reaches RemoveMember::execute() and
    // canRemoveMember(). So (Admin, Admin) has two different right answers on one route - an
    // admin may always leave, and may never remove another admin - and the pairs above are
    // satisfied by either. Without this block, a `leave` case that nobody ever wrote would
    // leave canLeave() with no HTTP coverage at all while every pair stayed green.
    //
    // The route list is a literal with its reason beside it, and that is this test's one
    // un-derived obligation: nothing readable from MemberRole says which controller branches
    // on self. If a later route starts dispatching on whether a path parameter names the
    // actor, it has to be added here by hand.
    $selfBranchRoutes = ['delete /plans/{plan}/members/{user}'];

    $allRoles = array_map(fn (MemberRole $role): string => $role->value, MemberRole::cases());
    sort($allRoles);

    expect($allRoles)->toBe(['admin', 'member', 'owner']);

    foreach ($selfBranchRoutes as $key) {
        $entry = planRoleMatrix()[$key]
            ?? throw new RuntimeException("No role-matrix entry for [{$key}], which the key-set assertions above should already have caught.");

        $selfCovered = [];

        foreach ($entry['cases'] as $case) {
            if ($case['target'] !== 'self') {
                continue;
            }

            foreach (array_keys($case['expect']) as $actorRole) {
                $selfCovered[] = $actorRole;
            }
        }

        $selfCovered = array_values(array_unique($selfCovered));
        sort($selfCovered);

        $missingSelf = array_values(array_diff($allRoles, $selfCovered));

        expect($missingSelf)->toBeEmpty("{$key} dispatches on whether {user} is the actor, so it needs a target=self case with a verdict for every role; these roles have none: ".implode(', ', $missingSelf));
    }

    // Fifteen routes carry cases, and the arithmetic is
    // 1+1+1+1+1+3+4+2+1+1+1+1+1+1+1: one each for GET/PATCH/DELETE /plans/{plan}, POST
    // /archive, GET /members, GET /invites, DELETE /invites/{invite}, GET /activity, GET
    // /messages, POST /messages and the chat feature's own two reaction routes, three for
    // PATCH /members/{user}, four for DELETE /members/{user} and two for POST /invites. The
    // three routes with no role check carry none, and so do the author-only edit/delete
    // message routes. The grand total is the floor under every route at once: a matrix whose
    // lists were all emptied fails here even where no pair obligation applies.
    expect($cases)->toBe(21);
});

it('answers 403 for every role the matrix forbids and 200, 201 or 204 for every role it allows', function (): void {
    $owner = userWithPassword();
    $admin = userWithPassword();
    $planMember = userWithPassword();
    $targetAdmin = userWithPassword();
    $targetMember = userWithPassword();

    [, , $ownerAccess] = signIn($owner);
    [, , $adminAccess] = signIn($admin);
    [, , $memberAccess] = signIn($planMember);

    $tokens = ['owner' => $ownerAccess, 'admin' => $adminAccess, 'member' => $memberAccess];
    $actors = ['owner' => $owner, 'admin' => $admin, 'member' => $planMember];

    // Laravel's own handler, not a render closure: AuthorizationException with no status
    // becomes AccessDeniedHttpException (Handler::prepareException()) and is encoded with
    // JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES. Written with explicit \n rather than as a
    // heredoc, so that re-indenting this file cannot change the value being asserted.
    $unauthorized = "{\n    \"message\": \"This action is unauthorized.\"\n}";

    // bootstrap/app.php's closure, compact. §9.2's actionable message, and the only way to
    // prove it reaches the client is to compare the bytes: the status is 403 either way.
    $ownerCannotLeave = '{"message":"Transfer ownership or archive this Plan before leaving it."}';

    $checked = 0;

    foreach (planRouteTable() as [$method, $path]) {
        $key = $method.' '.$path;

        $entry = planRoleMatrix()[$key]
            ?? throw new RuntimeException("No role-matrix entry for [{$key}]: add one to planRoleMatrix().");

        foreach ($entry['cases'] as $case) {
            foreach (['owner', 'admin', 'member'] as $role) {
                [$plan, $invite, $message] = roleMatrixPlan($owner, $admin, $planMember, $targetAdmin, $targetMember);

                $target = match ($case['target']) {
                    'self' => $actors[$role],
                    'owner' => $owner,
                    'admin' => $targetAdmin,
                    'member' => $targetMember,
                    // The route carries no {user}, so this value is never substituted;
                    // planRouteUri() ignores a parameter the path does not name.
                    'none' => $targetMember,
                    default => throw new RuntimeException("Unknown target [{$case['target']}] for [{$key}]."),
                };

                // 'message' and 'reaction' are likewise ignored by every route that does not
                // name them - only the chat feature's two reaction routes do. reactionPathSegment()
                // is the percent-encoded form a real HTTP client would send (see its own
                // docblock in tests/Pest.php); the raw emoji corrupts this test's own
                // Request::create() call on this project's pinned PHP build.
                $uri = planRouteUri($path, [
                    'plan' => $plan->id,
                    'user' => $target->id,
                    'invite' => $invite->id,
                    'message' => $message->id,
                    'reaction' => reactionPathSegment(),
                ]);

                $label = "{$key} [{$case['case']}] as {$role}";

                // Load-bearing: three bearer tokens are interleaved inside this one it(), and
                // Sanctum's guard caches the resolved user. Without this, every request after
                // the first is authenticated as the owner. Step C6 is the observation.
                forgetAuthenticatedUser();

                $response = withToken($tokens[$role])->json(strtoupper($method), $uri, $case['payload']);

                $status = $response->getStatusCode();

                match ($case['expect'][$role]) {
                    'allowed' => expect(in_array($status, [200, 201, 204], true))
                        ->toBeTrue("{$label} must be allowed, and spec §5.4 says an allowed answer is 200, 201 or 204 - got {$status}"),
                    'unauthorized' => expect([$status, $response->getContent()])
                        ->toBe([403, $unauthorized], "{$label} must answer 403 with Laravel's own pretty-printed unauthorized body"),
                    'owner-cannot-leave' => expect([$status, $response->getContent()])
                        ->toBe([403, $ownerCannotLeave], "{$label} must answer 403 with OwnerCannotLeave's own compact body (§9.2)"),
                    default => throw new RuntimeException("Unknown verdict [{$case['expect'][$role]}] for [{$label}]."),
                };

                $checked++;
            }
        }
    }

    // 21 cases x 3 roles. A filter that matched no routes, or a matrix whose lists were all
    // emptied, fails here rather than passing green with nothing asserted.
    expect($checked)->toBe(63);
});
