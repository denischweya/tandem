<?php

declare(strict_types=1);

use App\Application\Plans\RecordActivity;
use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanStatus;
use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Models\PlanActivity;
use App\Models\PlanInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('revokes a pending invite, stamps revoked_at from the clock and writes one invite.revoked row', function (): void {
    // A frozen clock, so "revoked_at = now" is exact rather than approximate. travel() cannot
    // reach it: SystemClock builds `new DateTimeImmutable('now')` because app/Domain imports
    // nothing from Illuminate, so it never consults Carbon::setTestNow, which is what travel()
    // moves.
    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($instant));

    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [$invite, $token] = invited($plan, $owner, MemberRole::Member, 'ada@example.test');

    [, , $access] = signIn($owner);

    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/invites/{$invite->id}")
        ->assertNoContent();

    // THE ROW IS NEVER DELETED. The migration's reason for nullOnDelete on
    // invited_by/accepted_by is the historical "who invited whom" trail; deleting the row on
    // revoke destroys the same trail from the other end.
    expect(PlanInvite::query()->count())->toBe(1);

    $revoked = PlanInvite::query()->sole();

    // From Clock, never now() - and the same rule plan_activity.created_at follows. Second
    // precision, against the instant this test froze - the column is timestamptz(6) and the
    // clock carries microseconds, but the comparison is against a value computed from the same
    // instant, so residual 15's whole-second truncation cannot make this unfalsifiable: format()
    // discards the same precision on both sides.
    expect($revoked->revoked_at?->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($instant->format('Y-m-d H:i:s'));
    expect($revoked->accepted_at)->toBeNull();
    expect($revoked->email)->toBe('ada@example.test');

    $activity = PlanActivity::query()->where('activity_type', ActivityType::InviteRevoked->value)->sole();

    expect($activity->plan_id)->toBe($plan->id);
    expect($activity->actor_id)->toBe($owner->id);
    // Against the constant, not the literal 'PlanInvite': the value belongs to the class that
    // owns RecordActivity, which matches this argument against its own per-type table and
    // throws InvalidArgumentException - an uncaught 500 - on a disagreement.
    expect($activity->subject_type)->toBe(RecordActivity::SUBJECT_INVITE);
    expect($activity->subject_id)->toBe($invite->id);
    // toEqual(), not toBe(): plan_activity.metadata is jsonb, and Postgres reorders a jsonb
    // object's keys (shortest-first, then bytewise) rather than preserving insertion order, so
    // toBe() on a non-empty jsonb value compares storage order rather than content. Harmless
    // here because the expected value is empty - there is nothing to reorder - but toEqual() is
    // the correct form for this column and is used for that reason, not because it matters for
    // an empty array specifically.
    expect($activity->metadata)->toEqual([]);
    // No metadata at all, and NEVER the address: the feed is read by every member, and an
    // invited address is personal data about someone who is not a member.
    expect(json_encode($activity->metadata))->not->toContain('ada@example.test');

    // And the revocation BITES. Without this, every assertion above is about a column rather
    // than about the credential: the token is dead at the one endpoint that resolves state from
    // it.
    getJson("/api/v1/invites/{$token}")
        ->assertNotFound()
        ->assertJsonPath('message', 'This invite link is no longer valid. Ask whoever invited you for a new one.');
});

it('answers 204 and writes nothing for an invite that was already revoked', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [$invite] = invited($plan, $owner, MemberRole::Member);

    DB::table('plan_invites')->where('id', $invite->id)
        ->update(['revoked_at' => '2026-09-01 08:00:00+00']);

    [, , $access] = signIn($owner);

    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/invites/{$invite->id}")
        ->assertNoContent();

    // Idempotent, and the ORIGINAL timestamp survives: re-stamping revoked_at would move the
    // moment the invite died, which is a fact the feed and the list both publish. This literal
    // is far outside residual 15's one-second truncation window, so a re-stamp (which would
    // move it to today) is unambiguously observable.
    expect(PlanInvite::query()->sole()->revoked_at?->format('Y-m-d H:i:s'))
        ->toBe('2026-09-01 08:00:00');

    // And no second activity row. plan_activity is immutable with no delete path, so two
    // "revoked" rows for one invite describe something that happened once as though it
    // happened twice, for ever.
    expect(PlanActivity::query()->where('activity_type', ActivityType::InviteRevoked->value)->count())
        ->toBe(0);
});

it('answers 204 and writes nothing for an invite that was already accepted', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [$invite] = invited($plan, $owner, MemberRole::Member);

    $joiner = User::factory()->hasProfile()->create();
    member($plan, $joiner, MemberRole::Member, '2026-01-03T00:00:00+00:00');

    DB::table('plan_invites')->where('id', $invite->id)
        ->update(['accepted_at' => '2026-09-02 08:00:00+00', 'accepted_by' => $joiner->id]);

    [, , $access] = signIn($owner);

    // A silent 204, NOT a 409. PlanInviteResource exposes accepted_at and
    // GET /plans/{plan}/invites necessarily precedes any revoke in a real client, so a client
    // offering "Revoke" on an invite its own data says was accepted is a client bug - and an
    // exception class, a status and a contract row to catch it cost more than they return.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/invites/{$invite->id}")
        ->assertNoContent();

    // revoked_at stays NULL. Stamping it would make the row say the invite was both used and
    // cancelled, and nothing in the schema says which came first.
    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('revoked_at')->count())
        ->toBe(1);
    expect(PlanInvite::query()->sole()->accepted_by)->toBe($joiner->id);
    expect(PlanActivity::query()->where('activity_type', ActivityType::InviteRevoked->value)->count())
        ->toBe(0);
});

it('answers one byte-identical invite-not-found 404 for an unknown, a malformed and another Plan\'s invite', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    $otherPlan = ownedPlan($owner);
    [$foreign] = invited($otherPlan, $owner, MemberRole::Member);

    [, , $access] = signIn($owner);

    $responses = [
        'unknown' => withToken($access)
            ->deleteJson("/api/v1/plans/{$plan->id}/invites/0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f"),
        // Not a uuid: without the shape guard this is Postgres 22P02 and a 500 on an
        // authenticated route, not a 404.
        'malformed' => withToken($access)
            ->deleteJson("/api/v1/plans/{$plan->id}/invites/not-a-uuid"),
        // The invite exists, the caller can see BOTH Plans, and the {invite} belongs to the
        // other one.
        'another Plan' => withToken($access)
            ->deleteJson("/api/v1/plans/{$plan->id}/invites/{$foreign->id}"),
    ];

    // The whole status map in ONE keyed assertion rather than a foreach of assertNotFound():
    // TestResponse::assertNotFound() takes no arguments and PHP silently ignores extra
    // arguments to a user-defined method, so a per-case message handed to it never reaches the
    // output. A keyed map names the failing case in its diff.
    $statuses = array_map(fn (TestResponse $r): int => $r->getStatusCode(), $responses);

    expect($statuses)->toBe([
        'unknown' => 404,
        'malformed' => 404,
        'another Plan' => 404,
    ]);

    // BYTE-identical, not merely equal JSON: a 404 arriving from Laravel's own handler - a
    // router miss, or a ModelNotFoundException from an implicit binding - is encoded with
    // JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES, while a render() closure's JsonResponse is
    // compact. assertJsonPath passes against both, which is why this compares the bytes.
    $bodies = array_map(fn (TestResponse $r): string => (string) $r->getContent(), $responses);

    expect(array_unique($bodies))->toHaveCount(1);
    expect($bodies['malformed'])->toBe('{"message":"That invite is no longer available."}');

    // The foreign invite is UNTOUCHED - the refusal is not a revoke with a 404 glued on.
    expect(DB::table('plan_invites')->where('id', $foreign->id)->whereNull('revoked_at')->count())
        ->toBe(1);
    expect(PlanActivity::query()->where('activity_type', ActivityType::InviteRevoked->value)->count())
        ->toBe(0);
});

it('refuses a plain member with 403 and a non-member with the Plan-not-found 404', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [$invite] = invited($plan, $owner, MemberRole::Member);

    $plainMember = userWithPassword();
    member($plan, $plainMember, MemberRole::Member, '2026-01-02T00:00:00+00:00');
    $stranger = userWithPassword();

    [, , $memberAccess] = signIn($plainMember);

    withToken($memberAccess)->deleteJson("/api/v1/plans/{$plan->id}/invites/{$invite->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    forgetAuthenticatedUser();

    [, , $strangerAccess] = signIn($stranger);

    // 404 and not 403, and it is PlanNotVisible's body rather than PlanInviteNotFound's: the
    // refusal happens at findVisibleTo(), before any invite is looked up, so a non-member
    // cannot even learn whether the {invite} resolves.
    withToken($strangerAccess)->deleteJson("/api/v1/plans/{$plan->id}/invites/{$invite->id}")
        ->assertNotFound()
        ->assertJsonPath('message', 'Plan not found.');

    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('revoked_at')->count())
        ->toBe(1);
});

it('refuses an archived Plan with 409 and writes nothing', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [$invite] = invited($plan, $owner, MemberRole::Member);

    DB::table('plans')->where('id', $plan->id)
        ->update(['status' => PlanStatus::Archived->value, 'archived_at' => now()]);

    [, , $access] = signIn($owner);

    // 409 and PlanNotEditable's body: revoking is a WRITE, and the archived refusal covers
    // writes. GET on the same Plan still answers 200 - ListPlanInvitesTest pins that.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/invites/{$invite->id}")
        ->assertStatus(409)
        ->assertJsonPath('message', 'This Plan has been archived.');

    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('revoked_at')->count())
        ->toBe(1);
    expect(PlanActivity::query()->where('activity_type', ActivityType::InviteRevoked->value)->count())
        ->toBe(0);
});

it('refuses an unverified owner with the verified gate', function (): void {
    $owner = userWithPassword(attributes: ['email_verified_at' => null]);
    $plan = ownedPlan($owner);
    [$invite] = invited($plan, $owner, MemberRole::Member);

    [, , $access] = signIn($owner);

    // This route is gated on `verified`: revoking acts on another person's invitation. The
    // body is EnsureEmailIsVerified's own, not AuthorizationException's default, and the 403
    // is conditional on the Accept header deleteJson() sets. See ListPlanInvitesTest for the
    // measured gap behind that, which is not this route's problem to fix.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/invites/{$invite->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');

    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('revoked_at')->count())
        ->toBe(1);
});

it('requires authentication', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [$invite] = invited($plan, $owner, MemberRole::Member);

    deleteJson("/api/v1/plans/{$plan->id}/invites/{$invite->id}")->assertUnauthorized();

    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('revoked_at')->count())
        ->toBe(1);
});
