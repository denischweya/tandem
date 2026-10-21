<?php

declare(strict_types=1);

use App\Application\Plans\CursorPage;
use App\Application\Plans\PageQuery;
use App\Application\Plans\PlanMemberRepository;
use App\Application\Plans\PlanMemberView;
use App\Application\Plans\RecordActivity;
use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Models\PlanActivity;
use App\Models\PlanInvite;
use App\Models\PlanMember;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

// No RateLimiter::clear() and no limiter assertions anywhere in this file: §13.4 gives this
// route no limit, deliberately - "the caller must hold a 64-hex single-use invite, so there is
// nothing to brute-force and nothing to flood that a second request would gain", which is
// /auth/reset-password's recorded reasoning on the same token shape.
//
// Every user who signs in comes from userWithPassword(): signIn() posts the fixed password
// 'correct horse battery staple' to the real POST /auth/login and asserts 200 on the way
// through, while UserFactory seeds Hash::make('password') - so signIn() on a plain factory user
// fails inside the helper with a 401 and the subject under test is never reached. A user who
// never signs in (every $owner below) stays on the factory.

it('joins a Plan with a link invite, writes one active membership and one member.joined row', function (): void {
    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($instant));

    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [$invite, $token] = invited($plan, $owner, MemberRole::Member);

    $joiner = userWithPassword();
    [, , $access] = signIn($joiner);

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.id', $plan->id)
        ->assertJsonPath('data.member_count', 2)
        ->assertJsonPath('data.viewer_role', MemberRole::Member->value);

    $membership = PlanMember::query()->where('user_id', $joiner->id)->sole();

    expect($membership->plan_id)->toBe($plan->id);
    expect($membership->role)->toBe(MemberRole::Member->value);
    expect($membership->status)->toBe(MemberStatus::Active->value);

    // From Clock and non-null, asserted to the second against the instant this test froze. A
    // keyset page over (joined_at, id) evaluates to NULL for a NULL joined_at and silently drops
    // the row from every members page after the first (baseline S-37), so "active with no
    // joined_at" is a correctness bug no status assertion can see.
    expect($membership->joined_at?->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($instant->format('Y-m-d H:i:s'));

    // Claimed once, and by the person who actually joined. §10.6 binds nothing to the invited
    // address, so accepted_by is the only honest record of who used the link.
    $claimed = PlanInvite::query()->sole();

    expect($claimed->id)->toBe($invite->id);
    expect($claimed->accepted_by)->toBe($joiner->id);
    expect($claimed->accepted_at?->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($instant->format('Y-m-d H:i:s'));
    expect($claimed->revoked_at)->toBeNull();

    $activity = PlanActivity::query()->where('activity_type', ActivityType::MemberJoined->value)->sole();

    expect($activity->plan_id)->toBe($plan->id);
    expect($activity->actor_id)->toBe($joiner->id);
    // Against the constant, not the literal 'User': the value belongs to the section that owns
    // RecordActivity, which matches this argument against its own per-type table and throws
    // InvalidArgumentException - an uncaught 500 - on a disagreement.
    expect($activity->subject_type)->toBe(RecordActivity::SUBJECT_USER);
    expect($activity->subject_id)->toBe($joiner->id);
    // An empty array has no keys to reorder, so toBe() is safe here even though
    // plan_activity.metadata is jsonb and Postgres reorders a jsonb object's keys on write -
    // ChangeMemberRoleTest documents toEqual() as the general remedy for a non-empty one.
    expect($activity->metadata)->toBe([]);
});

it('writes none of the three when the activity write fails, proving the transaction is real', function (): void {
    // §17.6 item 5's atomicity half, proved the way ChangeMemberRoleTest's "writes none of the
    // four when the activity write fails" proves it: a model hook that throws, not a mock -
    // RecordActivity is reached through a final readonly class and Mockery refuses such a class
    // outright - at the same injection point a mock would use, one frame deeper, and strictly
    // stronger because it also aborts the INSERT a real failure would abort. Nothing pops the
    // hook: the application is rebuilt for every test.
    //
    // The hook throws and CHANGES NOTHING ELSE: no argument is corrupted, no write is removed,
    // no call site is touched. The claim and the membership write genuinely execute first, so
    // the only thing that can explain their absence below is the rollback - the 500, which
    // arrives either way, is explicitly NOT the observation.
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [$invite, $token] = invited($plan, $owner, MemberRole::Member);

    $joiner = userWithPassword();
    [, , $access] = signIn($joiner);

    PlanActivity::saving(function (): void {
        throw new RuntimeException('the activity write failed');
    });

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertStatus(500);

    // §10.7's writes are all-or-none, and this is the "none" half - no status code can see it,
    // since a 500 arrives whether the writes rolled back or committed.
    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('accepted_at')->count())
        ->toBe(1, 'the claim rolled back');
    expect(PlanMember::query()->where('user_id', $joiner->id)->count())
        ->toBe(0, 'the membership write rolled back');
    expect(PlanActivity::query()->where('activity_type', ActivityType::MemberJoined->value)->count())
        ->toBe(0, 'no activity row survived');
});

it('stores the role the invite carries, so an admin invite joins as an admin', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [, $token] = invited($plan, $owner, MemberRole::Admin, 'ada@example.test');

    // The invited address is NOT bound to the accepting account (§10.6): Ada is invited at her
    // work address and joins with the account she actually has. The invite keeps the address it
    // was sent to, and accepted_by records who used it.
    $joiner = userWithPassword();
    [, , $access] = signIn($joiner);

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.viewer_role', MemberRole::Admin->value);

    expect(PlanMember::query()->where('user_id', $joiner->id)->sole()->role)
        ->toBe(MemberRole::Admin->value);

    $claimed = PlanInvite::query()->sole();

    expect($claimed->accepted_by)->toBe($joiner->id);
    expect($claimed->email)->toBe('ada@example.test');
});

it('lets an unverified user join, because the join route carries no verified gate', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [, $token] = invited($plan, $owner, MemberRole::Member);

    // §7.5's one exemption, and spec §17.7 is what makes it load-bearing: "the second person
    // opens the invite link on the web, registers, and joins", with no verification step. A
    // `verified` entry on this route makes that criterion unsatisfiable. An unverified user can
    // still sign in - nothing in the login path consults email_verified_at.
    //
    // userWithPassword(attributes: [...]) sets exactly what UserFactory::unverified() sets while
    // keeping the password signIn() posts.
    $joiner = userWithPassword(attributes: ['email_verified_at' => null]);
    [, , $access] = signIn($joiner);

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.id', $plan->id);

    expect(PlanMember::query()->where('user_id', $joiner->id)->sole()->status)
        ->toBe(MemberStatus::Active->value);
});

it('reuses the row of someone who previously left rather than inserting a second one', function (): void {
    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($instant));

    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    $returner = userWithPassword();

    // UNIQUE(plan_id, user_id) plus permanently-retained left/removed rows (§3.5) means anyone
    // who has ever left ALREADY HAS a row, and §9.3 guarantees one for every user who has ever
    // deleted an account - so §10.7 step 7 is an upsert rather than an insert, and this is the
    // row it has to find. Written as a 5-key attribute array because member() hardcodes
    // MemberStatus::Active and takes no status.
    $existing = PlanMember::factory()->create([
        'plan_id' => $plan->id,
        'user_id' => $returner->id,
        'role' => MemberRole::Member->value,
        'status' => MemberStatus::Left->value,
        'joined_at' => '2026-01-02T00:00:00+00:00',
    ]);

    [, $token] = invited($plan, $owner, MemberRole::Admin);
    [, , $access] = signIn($returner);

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.member_count', 2)
        ->assertJsonPath('data.viewer_role', MemberRole::Admin->value);

    // The SAME row, updated in place. A second insert is a 23505 and a 500, so this count is the
    // assertion that the upsert is an upsert.
    expect(PlanMember::query()->where('user_id', $returner->id)->count())->toBe(1);

    $reused = PlanMember::query()->whereKey($existing->id)->sole();

    expect($reused->status)->toBe(MemberStatus::Active->value);
    expect($reused->role)->toBe(MemberRole::Admin->value);
    expect($reused->joined_at?->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($instant->format('Y-m-d H:i:s'));
});

it('answers 200 and writes nothing when the actor is already an active member', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    $existing = userWithPassword();
    member($plan, $existing, MemberRole::Member, '2026-01-02T00:00:00+00:00');

    // An ADMIN invite, deliberately: §10.7 step 5 must not change an existing active member's
    // role, or accepting an admin invite while already a Member is a promotion through the join
    // route, bypassing `assign admins` (§3.2).
    [$invite, $token] = invited($plan, $owner, MemberRole::Admin);
    [, , $access] = signIn($existing);

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.id', $plan->id)
        ->assertJsonPath('data.viewer_role', MemberRole::Member->value);

    // The second request is the one §10.7 step 5 is written for: a double-tapped accept button,
    // or the same link opened twice, must answer the same thing and must not burn a single-use
    // credential for nothing.
    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.viewer_role', MemberRole::Member->value);

    // Counted rather than read as a timestamp: a count fails with an integer message, and "still
    // pending" is exactly what a WHERE accepted_at IS NULL count asserts. The invite is still
    // usable by the person it was meant for, and it still expires and can still be revoked.
    expect(DB::table('plan_invites')->where('id', $invite->id)
        ->whereNull('accepted_at')->whereNull('revoked_at')->count())->toBe(1);

    expect(PlanMember::query()->where('user_id', $existing->id)->sole()->role)
        ->toBe(MemberRole::Member->value);
    expect(PlanMember::query()->count())->toBe(2);
    expect(PlanActivity::query()->where('activity_type', ActivityType::MemberJoined->value)->count())
        ->toBe(0);
});

it('refuses a second acceptance of the same invite, because an invite is single use', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [, $token] = invited($plan, $owner, MemberRole::Member);

    $first = userWithPassword();
    [, , $firstAccess] = signIn($first);

    withToken($firstAccess)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertOk();

    // A SECOND person with the same link - the case §10.2 states the product cost of: you cannot
    // paste one invite link into a group chat and have five people join through it; five people
    // need five invites.
    //
    // forgetAuthenticatedUser() is not optional. The guards cache the resolved user for the whole
    // test, so without it the second request authenticates as $first no matter which bearer token
    // it carries - measured, and recorded on the helper itself.
    forgetAuthenticatedUser();

    $second = userWithPassword();
    [, , $secondAccess] = signIn($second);

    withToken($secondAccess)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertNotFound()
        ->assertJsonPath('message', 'This invite link is no longer valid. Ask whoever invited you for a new one.');

    expect(PlanMember::query()->where('user_id', $second->id)->count())->toBe(0);
    expect(PlanInvite::query()->sole()->accepted_by)->toBe($first->id);
    expect(PlanActivity::query()->where('activity_type', ActivityType::MemberJoined->value)->count())
        ->toBe(1);
});

it('answers one byte-identical 404 for every way the join can fail', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    $otherPlan = ownedPlan($owner);

    [, $valid] = invited($plan, $owner, MemberRole::Member);
    [$expiredInvite, $expired] = invited($plan, $owner, MemberRole::Member);
    [$revokedInvite, $revoked] = invited($plan, $owner, MemberRole::Member);
    [$acceptedInvite, $accepted] = invited($plan, $owner, MemberRole::Member);
    [, $foreign] = invited($otherPlan, $owner, MemberRole::Member);

    // Driven straight at the columns: these three states have no endpoint that produces them
    // until Task D ships, and this task must not depend on it.
    DB::table('plan_invites')->where('id', $expiredInvite->id)
        ->update(['expires_at' => now()->subMinute()]);
    DB::table('plan_invites')->where('id', $revokedInvite->id)
        ->update(['revoked_at' => now()]);
    DB::table('plan_invites')->where('id', $acceptedInvite->id)
        ->update(['accepted_at' => now(), 'accepted_by' => $owner->id]);

    $archived = ownedPlan($owner);
    [, $archivedToken] = invited($archived, $owner, MemberRole::Member);
    DB::table('plans')->where('id', $archived->id)
        ->update(['status' => PlanStatus::Archived->value, 'archived_at' => now()]);

    $joiner = userWithPassword();
    [, , $access] = signIn($joiner);

    $responses = [
        'unknown token' => withToken($access)
            ->postJson("/api/v1/plans/{$plan->id}/join", ['token' => str_repeat('a', 64)]),
        'token for another Plan' => withToken($access)
            ->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $foreign]),
        'unknown plan' => withToken($access)
            ->postJson('/api/v1/plans/0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f/join', ['token' => $valid]),
        // A uuid column never sees this value: the plan check is a string comparison against the
        // invite row, so there is no 22P02 and no 500.
        'malformed plan' => withToken($access)
            ->postJson('/api/v1/plans/not-a-uuid/join', ['token' => $valid]),
        'expired' => withToken($access)
            ->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $expired]),
        'revoked' => withToken($access)
            ->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $revoked]),
        'accepted' => withToken($access)
            ->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $accepted]),
        'archived plan' => withToken($access)
            ->postJson("/api/v1/plans/{$archived->id}/join", ['token' => $archivedToken]),
    ];

    // The whole status map in ONE keyed assertion rather than a foreach of assertNotFound().
    // TestResponse::assertNotFound() takes NO arguments, and PHP silently ignores extra
    // arguments to a user-defined method - so a "$reason must answer 404" message handed to it
    // never reaches the output and a failing run cannot say WHICH of the eight cases broke. A
    // keyed map names it in the diff, and a case dropped from the list above changes the map's
    // size, so the enumeration cannot quietly shrink.
    $statuses = array_map(fn (TestResponse $r): int => $r->getStatusCode(), $responses);

    expect($statuses)->toBe([
        'unknown token' => 404,
        'token for another Plan' => 404,
        'unknown plan' => 404,
        'malformed plan' => 404,
        'expired' => 404,
        'revoked' => 404,
        'accepted' => 404,
        'archived plan' => 404,
    ]);

    // BYTE-identical, not merely equal JSON. A refusal that reached Laravel's own handler -
    // a router miss, a ModelNotFoundException, or PlanNotVisible's closure - would differ in
    // whitespace or in text, and assertJsonPath passes against both encodings.
    $bodies = array_map(fn (TestResponse $r): string => (string) $r->getContent(), $responses);

    expect(array_unique($bodies))->toHaveCount(1);
    expect($bodies['unknown token'])
        ->toBe('{"message":"This invite link is no longer valid. Ask whoever invited you for a new one."}');

    // Nothing was written by any of the eight, and the still-good token still works - so this
    // test is not passing because every join 404s.
    expect(PlanMember::query()->where('user_id', $joiner->id)->count())->toBe(0);

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $valid])->assertOk();
});

it('refuses an owner-role invite with 409, logs it, and writes no second active owner row', function (): void {
    // Human ruling (controller, 2026-10-20): the migration freeze forbids a CHECK on
    // plan_invites.role, so AcceptInvite is the only place §9.1's one-owner invariant can be
    // protected on this path, and the refusal is 409 plus a Log::warning() rather than the
    // uniform 404 every other refusal on this route shares - this is a data-integrity signal,
    // not expected control flow.
    //
    // Reachable only by a direct database write: InvitePlanMemberRequest's in:admin,member and
    // PlanPermissions::canInviteWithRole() mean nothing in the API can mint an owner-role
    // invite. Driven straight at the column for the same reason the byte-identical-404 test
    // above drives expired/revoked/accepted straight at the columns - no endpoint produces this
    // state.
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [$invite, $token] = invited($plan, $owner, MemberRole::Member);
    DB::table('plan_invites')->where('id', $invite->id)->update(['role' => MemberRole::Owner->value]);

    $joiner = userWithPassword();
    [, , $access] = signIn($joiner);

    $logs = captureLogs();

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertStatus(409)
        ->assertJsonPath(
            'message',
            'This invite cannot be accepted because it would make you a second owner of this Plan.',
        );

    // The log is the point: without it the invite is permanently unacceptable, the invitee is
    // told only "conflict", and nobody is ever told a row exists that the API should never have
    // written.
    $warnings = array_values(array_filter(
        capturedLogRecords($logs),
        fn (array $record): bool => $record['message'] === 'security.invite.owner_role_refused',
    ));

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['level'])->toBe('WARNING');
    expect($warnings[0]['context']['plan_id'] ?? null)->toBe($plan->id);
    expect($warnings[0]['context']['invite_id'] ?? null)->toBe($invite->id);
    expect($warnings[0]['context']['actor_id'] ?? null)->toBe($joiner->id);

    // §9.1's invariant, asserted on this path exactly as OwnershipInvariantTest asserts it on
    // its own: exactly one role = owner, status = active row for the Plan, unchanged by the
    // refused request. The throw sits before the first write (claim, membership, activity), so
    // there is nothing left to assert absent beyond this and the three below.
    expect(PlanMember::query()
        ->where('plan_id', $plan->id)
        ->where('role', MemberRole::Owner->value)
        ->where('status', MemberStatus::Active->value)
        ->count())->toBe(1, 'the §9.1 invariant: exactly one active owner row for the Plan, unchanged');

    expect(PlanMember::query()->where('user_id', $joiner->id)->count())->toBe(0);
    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('accepted_at')->count())->toBe(1);
    expect(PlanActivity::query()->where('activity_type', ActivityType::MemberJoined->value)->count())->toBe(0);
});

it('answers 422 for a token of the wrong length, before any invite lookup', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [, $token] = invited($plan, $owner, MemberRole::Member);

    $joiner = userWithPassword();
    [, , $access] = signIn($joiner);

    // size:64 is RefreshTokenRequest's rule on the same 64-hex token shape, and the split is the
    // one InviteNotUsable's docblock draws: a wrong-SHAPED field is a field error, an unusable
    // credential is not. A 64-character token that is not hex is NOT a field error - it is a miss
    // on a varchar column and answers the uniform 404 above.
    //
    // '' is in the list because Laravel's global ConvertEmptyStringsToNull turns it into null,
    // which `required` refuses - so an empty token is a 422 rather than a lookup for the empty
    // string's sha256.
    foreach (['', substr($token, 0, 63), $token.'a'] as $badToken) {
        withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $badToken])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('token');
    }

    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", [])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('token');

    expect(PlanMember::query()->where('user_id', $joiner->id)->count())->toBe(0);
});

it('requires authentication, because accepted_by has to name a real account', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [, $token] = invited($plan, $owner, MemberRole::Member);

    postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])->assertUnauthorized();

    expect(DB::table('plan_invites')->whereNull('accepted_at')->count())->toBe(1);
    expect(PlanMember::query()->count())->toBe(1);
});

it('reports the already-a-member case when a concurrent join committed first', function (): void {
    // §17.6 item 5, measured rather than predicted - and modelled with no concurrency at all.
    // plan_members already holds an ACTIVE row for this (plan_id, user_id) pair while the
    // repository's FIRST read answers null, which is exactly the state of the world between
    // AcceptInvite's read and its insert when another request commits in the gap. The insert then
    // violates plan_members_plan_id_user_id_unique and Postgres aborts the transaction.
    //
    // A hand-written double rather than Pest\Laravel\mock(): the suite mocks nothing, and Mockery
    // refuses a final readonly class outright - the Eloquent implementation of this interface is
    // one. ANONYMOUS, because a named class declared in a Pest file is process-global exactly as
    // a function is, and the second file to declare that name is a fatal "Cannot redeclare", not
    // a test failure.
    //
    // FIVE methods stubbed, not four: PlanMemberRepository gained findActiveMemberView() after
    // this interface's four-method shape was first drafted, so the double has to implement it
    // too or PHP fatals ("Class@anonymous ... must implement findActiveMemberView") before this
    // test ever runs.
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [$invite, $token] = invited($plan, $owner, MemberRole::Admin);

    $joiner = userWithPassword();
    member($plan, $joiner, MemberRole::Member, '2026-01-03T00:00:00+00:00');

    [, , $access] = signIn($joiner);

    $real = app(PlanMemberRepository::class);

    app()->instance(PlanMemberRepository::class, new class($real) implements PlanMemberRepository
    {
        private int $reads = 0;

        public function __construct(private PlanMemberRepository $real) {}

        public function findActiveMember(string $planId, string $userId): PlanMember
        {
            return $this->real->findActiveMember($planId, $userId);
        }

        public function findActiveMemberView(string $planId, string $userId): PlanMemberView
        {
            return $this->real->findActiveMemberView($planId, $userId);
        }

        public function findTransferTarget(string $planId, string $userId): PlanMember
        {
            return $this->real->findTransferTarget($planId, $userId);
        }

        public function findMembership(string $planId, string $userId): ?PlanMember
        {
            $this->reads++;

            // Read 1 is AcceptInvite's own, inside the transaction, before the other request
            // committed. Read 2 is the one in the catch, after the rollback, and it must see the
            // row the other request left behind.
            return $this->reads === 1 ? null : $this->real->findMembership($planId, $userId);
        }

        /** @return CursorPage<PlanMemberView> */
        public function listActiveMembers(string $planId, PageQuery $query): CursorPage
        {
            return $this->real->listActiveMembers($planId, $query);
        }
    });

    // 200 rather than 500, and HOW it is reached is the whole measurement: the catch sits AROUND
    // DB::transaction(), so by the time it runs the rollback has already happened, $e is the real
    // UniqueConstraintViolationException rather than a rollback artifact, and the connection is
    // still usable for the read the catch performs. Catching inside the closure leaves the
    // transaction aborted and the next statement fails with 25P02.
    withToken($access)->postJson("/api/v1/plans/{$plan->id}/join", ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.id', $plan->id)
        ->assertJsonPath('data.viewer_role', MemberRole::Member->value);

    // The claim was rolled back with everything else in the closure, so the invite is still
    // pending: it can still be used by the person it was meant for, and it still expires.
    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('accepted_at')->count())
        ->toBe(1);

    // One membership row, still the one that was already there, and still `member` - the recovery
    // path reports the already-a-member case and changes nothing, the role included.
    expect(PlanMember::query()->where('user_id', $joiner->id)->count())->toBe(1);
    expect(PlanMember::query()->where('user_id', $joiner->id)->sole()->role)
        ->toBe(MemberRole::Member->value);
    expect(PlanActivity::query()->where('activity_type', ActivityType::MemberJoined->value)->count())
        ->toBe(0);
});
