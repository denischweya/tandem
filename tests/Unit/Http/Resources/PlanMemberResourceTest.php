<?php

declare(strict_types=1);

use App\Application\Plans\PlanMemberView;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Http\Resources\Api\V1\Plans\PlanMemberResource;
use App\Models\PlanMember;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

it('renders every published field', function (): void {
    $summary = new PlanMemberView(
        userId: '0192f0a1-0000-7000-8000-000000000002',
        displayName: 'Ada Lovelace',
        role: MemberRole::Member,
        status: MemberStatus::Active,
        joinedAt: Carbon::parse('2026-09-01 09:00:00+00:00'),
    );

    expect((new PlanMemberResource($summary))->toArray(new Request))->toBe([
        'user_id' => '0192f0a1-0000-7000-8000-000000000002',
        'display_name' => 'Ada Lovelace',
        'role' => 'member',
        'status' => 'active',
        // Milliseconds, not the brief's literal: DATE_RFC3339_EXTENDED is 'Y-m-d\TH:i:s.vP'
        // (lowercase v), measured by probe (D-R4) to always render three millisecond digits,
        // even for a zero-microsecond Carbon instant - '2026-09-01T09:00:00+00:00' (no .000)
        // is not a string this format can produce. The same brief-vs-measured gap PlanResourceTest
        // (Task 11) already found and fixed for PlanResource; fixed here against the measured
        // output for the same reason, rather than weakening PlanMemberResource's format() call.
        'joined_at' => '2026-09-01T09:00:00.000+00:00',
    ]);
});

// Not the brief's literal fixture - PlanMember::factory()->make(['status' => 'active',
// 'joined_at' => null]) - which cannot run in this file. tests/Unit boots no application
// (tests/Pest.php's single uses(TestCase::class)->in('Feature') line names Feature only), and
// Factory::make()'s own expandAttributes() resolves every nested factory default by calling
// ->create() on it regardless of whether the outer call is make() or create() - here that is
// 'plan_id' => Plan::factory() and 'user_id' => User::factory(), both left at their
// definition() default because this fixture only overrides status and joined_at. Measured:
// running the brief's literal line in this file - alone, under --testsuite=Unit, and under the
// full suite - fails identically with "A facade root has not been set." from
// database/factories/UserFactory.php:35, before this test's own assertions ever run. G6's class
// of defect, one file over from where it was last found on this wave.
//
// The fix is a bare `new PlanMember`, never fill()'d or factory()'d: PlanMember::$guarded is
// ['*'] (totallyGuarded()), so fill() throws MassAssignmentException on the first supplied key,
// and a factory is otherwise the only array-shaped write path (Global Constraints). Direct
// property assignment goes through setAttribute(), which checks neither guard. joined_at is
// left at its unset default (null) rather than assigned, because assigning any NON-null value
// to it would hit the same wall from the other side: HasAttributes::setAttribute()'s date
// branch calls fromDateTime(), which calls getDateFormat() -> getConnection() -> a null
// resolver. Needed only for this one throw case, where joined_at must be null anyway.
it('throws building the DTO from a member with no joined_at, rather than publishing a lie', function (): void {
    $member = new PlanMember;
    $member->status = MemberStatus::Active->value;

    expect(fn () => PlanMemberView::fromMember($member, 'Ada Lovelace'))
        ->toThrow(RuntimeException::class, 'PlanMember carried a NULL joined_at for an active row. '
            .'The invariant status = active => joined_at IS NOT NULL was violated upstream (baseline S-37).');
});
