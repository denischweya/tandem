<?php

declare(strict_types=1);

use App\Application\Plans\RecordActivity;
use App\Domain\Plans\ActivityType;
use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseEmpty;

uses(RefreshDatabase::class);

/**
 * The one writer of plan_activity (spec §11.2). Four things are asserted here that no other
 * file can assert, because every other test that produces an activity row produces it through
 * a use case that has its own reasons to fail:
 *
 *   - created_at comes from Clock::now() and not from now(). The column is NOT NULL with no
 *     default and the model has $timestamps = false, so Eloquent fills in nothing - which is
 *     the whole reason this class exists rather than nine inline inserts.
 *   - the write joins the caller's transaction instead of opening its own.
 *   - spec §11.1's subject_type/metadata table, as a refusal rather than as a comment.
 *   - spec §11.4: nothing reaches audit_logs, and nothing reaches the log.
 *
 * Imports arrive with the case that needs them: DB and assertDatabaseEmpty with A.7, nothing
 * else later. Pint's Laravel preset includes no_unused_imports, so adding them earlier makes
 * `pint --test` fail on an intermediate state.
 */
it('writes one row with created_at taken from the Clock, to the second', function (): void {
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();

    // A FrozenClock with a microsecond fraction, deliberately: the assertion below is that the
    // fraction is GONE. Eloquent's datetime cast formats through Grammar::getDateFormat(),
    // which is 'Y-m-d H:i:s' (vendor/laravel/framework/src/Illuminate/Database/Grammar.php:284)
    // and has no microsecond token, so the sub-second part of Clock::now() never reaches
    // Postgres even though plan_activity.created_at is timestamptz(6). That is asserted rather
    // than noted because the feed's keyset cursor tiebreaks on (created_at, id): if this ever
    // starts preserving microseconds, ties stop being the common case and the cursor test in
    // ActivityFeedTest stops exercising what its name claims.
    app()->instance(Clock::class, new FrozenClock(
        new DateTimeImmutable('2026-03-04 05:06:07.891011', new DateTimeZone('UTC')),
    ));

    app(RecordActivity::class)->execute(
        $plan->id,
        $actor->id,
        ActivityType::PlanCreated,
        null,
        null,
        [],
    );

    assertDatabaseCount('plan_activity', 1);

    $row = PlanActivity::query()->sole();

    // Three of these six column assertions cannot fail on their own, and the case that covers
    // them is the one in step A.9. plan_activity.metadata is jsonb NOT NULL DEFAULT '{}' and
    // subject_type/subject_id are nullable with no default, so deleting any of those three keys
    // from the INSERT leaves exactly these values behind - [] and null and null. They are
    // asserted here because this is the row's full shape, and the assertion that can actually
    // catch a dropped key is "stores the subject and the metadata it was given, not the column
    // default", which uses a non-empty value for each. This is spec defect S-2's lesson applied
    // before it bites: a column default makes the green outcome available for free.
    expect($row->plan_id)->toBe($plan->id)
        ->and($row->actor_id)->toBe($actor->id)
        ->and($row->activity_type)->toBe('plan.created')
        ->and($row->subject_type)->toBeNull()
        ->and($row->subject_id)->toBeNull()
        ->and($row->metadata)->toBe([])
        ->and($row->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-03-04 05:06:07.000000');
});

it('writes inside the transaction the caller already opened rather than opening its own', function (): void {
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();

    $observed = [];
    $callerLevel = null;

    // A model event as the observation point, not a mock: RecordActivity is `final readonly`
    // and Mockery refuses such a class outright, which is why DeleteAccountTest.php:296-303
    // throws from OutboxEvent::saving() instead of mocking OutboxWriter. The listener is
    // registered on this test's own event dispatcher, which is rebuilt with the application
    // for every test, so it cannot leak into the next one.
    //
    // The assertion is RELATIVE - the level inside the insert against the level the caller
    // saw - because RefreshDatabase has already opened a transaction of its own, so the
    // absolute number is an artifact of the test harness and not of this class.
    PlanActivity::created(function () use (&$observed): void {
        $observed[] = DB::transactionLevel();
    });

    DB::transaction(function () use (&$callerLevel, $plan, $actor): void {
        $callerLevel = DB::transactionLevel();

        app(RecordActivity::class)->execute(
            $plan->id,
            $actor->id,
            ActivityType::PlanCreated,
            null,
            null,
            [],
        );
    });

    expect($observed)->toBe([$callerLevel]);
});

// NO BREAK STEP EXISTS FOR THIS CASE, and that is recorded rather than hidden. There is no way
// to make RecordActivity's INSERT escape the caller's transaction without giving it a second
// connection, so this case's red has never been observed and it does not count as a guard by
// this project's standard. It is kept because it is the executable form of §11.2's rule for a
// reader, and because it would catch a future RecordActivity that queued a job or wrote through
// a second connection. The guard that actually bites is the transaction-level case above it,
// whose red is observed at A.8.
it('leaves no row behind when the caller transaction rolls back', function (): void {
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();

    try {
        DB::transaction(function () use ($plan, $actor): void {
            app(RecordActivity::class)->execute(
                $plan->id,
                $actor->id,
                ActivityType::PlanArchived,
                null,
                null,
                [],
            );

            throw new RuntimeException('the use case failed after writing its activity row');
        });
    } catch (RuntimeException) {
        // The throw is the point: a Plan that was not archived must not have an "archived" row.
    }

    assertDatabaseEmpty('plan_activity');
});

it('refuses a subject on an activity type that section 11.1 gives none', function (): void {
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();

    expect(fn () => app(RecordActivity::class)->execute(
        $plan->id,
        $actor->id,
        ActivityType::PlanUpdated,
        RecordActivity::SUBJECT_USER,
        $actor->id,
        ['fields' => ['title']],
    ))->toThrow(
        InvalidArgumentException::class,
        'Activity plan.updated carries no subject, so subject_type and subject_id must both be null.',
    );

    assertDatabaseEmpty('plan_activity');
});

it('refuses the wrong subject type for an activity that has one', function (): void {
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();

    expect(fn () => app(RecordActivity::class)->execute(
        $plan->id,
        $actor->id,
        ActivityType::MemberJoined,
        RecordActivity::SUBJECT_INVITE,
        $actor->id,
        [],
    ))->toThrow(
        InvalidArgumentException::class,
        "Activity member.joined requires subject_type 'User' and a non-null subject_id.",
    );

    assertDatabaseEmpty('plan_activity');
});

it('refuses metadata whose keys are not the ones section 11.1 assigns to the type', function (): void {
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();

    expect(fn () => app(RecordActivity::class)->execute(
        $plan->id,
        $actor->id,
        ActivityType::MemberRoleChanged,
        RecordActivity::SUBJECT_USER,
        $actor->id,
        ['from' => 'member'],
    ))->toThrow(
        InvalidArgumentException::class,
        'Activity member.role_changed requires metadata keys [from, to], got [from].',
    );

    assertDatabaseEmpty('plan_activity');
});

it('stores the subject and the metadata it was given, not the column default', function (): void {
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();
    $target = User::factory()->hasProfile()->create();

    // Non-empty values for all three of the keys whose absence is invisible: metadata is jsonb
    // NOT NULL DEFAULT '{}' and the two subject columns are nullable with no default, so the
    // "writes one row" case above cannot tell a stored [] from a dropped key. This one can.
    app(RecordActivity::class)->execute(
        $plan->id,
        $actor->id,
        ActivityType::MemberRoleChanged,
        RecordActivity::SUBJECT_USER,
        $target->id,
        ['from' => 'member', 'to' => 'admin'],
    );

    $row = PlanActivity::query()->sole();

    // Brief defect, measured rather than inherited: the task-5 brief's original assertion here
    // compared $row->metadata to ['from' => 'member', 'to' => 'admin'] with a plain ->toBe(),
    // which assumes jsonb round-trips preserve key insertion order. It does not - Postgres's
    // jsonb storage format reorders object keys by length then lexicographically, measured
    // directly: `SELECT '{"from": "member", "to": "admin"}'::jsonb` returns
    // `{"to": "admin", "from": "member"}`. Running the brief's literal assertion fails with
    // "Failed asserting that two arrays are identical" on key order alone, with identical
    // key=>value pairs on both sides. ksort on both sides keeps the comparison exact (every
    // key maps to the value this test gave it) without depending on an order Postgres does not
    // promise - unlike toEqualCanonicalizing(), which this suite already uses elsewhere
    // (RefreshChainTest.php:114) but only ever against a plain list of key NAMES, never against
    // key=>value pairs, where its semantics for an associative array are not a guarantee this
    // test wants to lean on.
    $metadata = $row->metadata;
    ksort($metadata);

    expect($row->subject_type)->toBe('User')
        ->and($row->subject_id)->toBe($target->id)
        ->and($metadata)->toBe(['from' => 'member', 'to' => 'admin']);
});

it('writes a row for every one of the nine types, with the shape section 11.1 gives it', function (): void {
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();
    $record = app(RecordActivity::class);

    // Spec §11.4, captured before any call below so the nine-call loop this test already makes
    // is also the exhaustiveness loop for B5b (vacuous-guard review, 2026-10-16): the dedicated
    // audit_logs/log test further down calls execute() exactly once, with MemberRemoved, so a
    // mutation that gates the audit_logs insert on any OTHER one of the nine types would pass
    // that test while still writing the row. Checked again at the bottom of this test, across
    // all nine, closes that gap.
    $logs = captureLogs();

    // §11.1's table, transcribed - the same kind of transcription EnumTest makes of blueprint
    // §9, and for the same reason: driven off ActivityType::cases() so that a tenth case fails
    // the completeness assertion below BY NAME rather than being silently unexercised. An
    // earlier draft of this case listed nine calls literally and claimed in a comment that a
    // tenth case "throws UnhandledMatchError, which this case reports" - it does not, because a
    // case nobody passes to shapeOf() is never matched.
    //
    // $plan->id stands in for a plan_invites.id in the two PlanInvite-subject rows on purpose:
    // plan_activity.subject_id carries no foreign key, so the column accepts any uuid and this
    // test needs no PlanInvite row (that model belongs to the invites section).
    $shapes = [
        'plan.created' => [null, null, []],
        'plan.updated' => [null, null, ['fields' => ['title', 'description']]],
        'plan.archived' => [null, null, []],
        'member.invited' => [RecordActivity::SUBJECT_INVITE, $plan->id, ['channel' => 'link', 'role' => 'member']],
        'member.joined' => [RecordActivity::SUBJECT_USER, $actor->id, []],
        'member.left' => [RecordActivity::SUBJECT_USER, $actor->id, []],
        'member.removed' => [RecordActivity::SUBJECT_USER, $actor->id, []],
        'member.role_changed' => [RecordActivity::SUBJECT_USER, $actor->id, ['from' => 'member', 'to' => 'admin']],
        'invite.revoked' => [RecordActivity::SUBJECT_INVITE, $plan->id, []],
    ];

    expect(array_map(fn (ActivityType $type) => $type->value, ActivityType::cases()))
        ->toBe(array_keys($shapes));

    foreach (ActivityType::cases() as $type) {
        [$subjectType, $subjectId, $metadata] = $shapes[$type->value];

        $record->execute($plan->id, $actor->id, $type, $subjectType, $subjectId, $metadata);
    }

    assertDatabaseCount('plan_activity', 9);

    expect(PlanActivity::query()->orderBy('activity_type')->pluck('activity_type')->all())
        ->toBe([
            'invite.revoked',
            'member.invited',
            'member.joined',
            'member.left',
            'member.removed',
            'member.role_changed',
            'plan.archived',
            'plan.created',
            'plan.updated',
        ]);

    // B5a (vacuous-guard review, 2026-10-16): the assertion above pins ORDER and COUNT across
    // all nine types, but not the WIRE VALUE of subject_type - $shapes supplies
    // RecordActivity::SUBJECT_INVITE/SUBJECT_USER and shapeOf() returns the very same
    // constants, so a mutation to either constant moves expected and actual together and the
    // assertion above stays green while member.invited and invite.revoked publish the wrong
    // subject_type to every client. This pins the actual string by literal, independent of the
    // constant under test - 'PlanInvite' and 'User' appear nowhere else as literals in this
    // file before this line.
    expect(PlanActivity::query()->orderBy('activity_type')->pluck('subject_type', 'activity_type')->all())
        ->toBe([
            'invite.revoked' => 'PlanInvite',
            'member.invited' => 'PlanInvite',
            'member.joined' => 'User',
            'member.left' => 'User',
            'member.removed' => 'User',
            'member.role_changed' => 'User',
            'plan.archived' => null,
            'plan.created' => null,
            'plan.updated' => null,
        ]);

    // B5b (vacuous-guard review, 2026-10-16): see the comment above $logs - this closes the
    // gap left by the dedicated single-call audit_logs test below, across all nine types
    // rather than one.
    assertDatabaseEmpty('audit_logs');
    expect($logs->getRecords())->toHaveCount(0);
});

it('writes nothing to audit_logs and emits no log line at all', function (): void {
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();

    // Spec §11.4: audit_logs stays empty for the whole of Wave 2, and the decision belongs to
    // the cycle that builds blueprint §93's moderation surface. The three events that look most
    // like its content - a member removed, a role changed, an invite revoked - are plan_activity
    // rows every member can read; what audit_logs would add is an actor-plus-IP record for
    // operators, a different consumer with a different audience, and it has no reader, no
    // retention decision and no model. Asserted here rather than stated in prose because this
    // is the one class that would be the natural place to start writing it.
    //
    // And blueprint §126: no Plan title, no invited address and no member email may reach the
    // application log. Wave 2 adds no log line at all, so the assertion is on the count and not
    // on the content - a line whose content was then reviewed is a line someone has to review.
    //
    // This case calls execute() exactly once, with MemberRemoved - by itself that only proves
    // the rule for one of the nine types (B5b, vacuous-guard review, 2026-10-16: a mutation
    // gating the audit_logs insert on any other type would pass this test undetected). The
    // nine-type loop above repeats both assertions across every type and is what actually
    // closes that gap; this case is kept as the focused, single-call demonstration the break
    // step below targets.
    $logs = captureLogs();

    app(RecordActivity::class)->execute(
        $plan->id,
        $actor->id,
        ActivityType::MemberRemoved,
        RecordActivity::SUBJECT_USER,
        $actor->id,
        [],
    );

    assertDatabaseCount('plan_activity', 1);
    assertDatabaseEmpty('audit_logs');
    expect($logs->getRecords())->toHaveCount(0);
});
