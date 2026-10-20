<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Database\Factories\PlanMemberFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\freezeTime;

uses(RefreshDatabase::class);

it('archives a Plan the way the application does, both fields', function (): void {
    // DeleteAccount's no-successor archive branch and §7.4 both write status AND archived_at. A
    // fixture that writes only status produces a shape no code path produces, and
    // PlanTest.php's "still lets forceFill and the factories write those columns" is exactly
    // that fixture.
    //
    // Residual 23: ->not->toBeNull() is green for any non-null value the factory happens to
    // set, which is weaker than this it()'s own name promises for archived_at. PlanFactory::
    // archived() writes 'archived_at' => now() (the bare helper, not Clock::now()), so
    // freezeTime() pins what "the value the factory actually sets" actually is.
    //
    // Compared to the second, not the microsecond: residual 15 - setAttribute()'s own
    // fromDateTime() call formats a date-cast value through Grammar::getDateFormat(), 'Y-m-d
    // H:i:s', with no microseconds, the moment the attribute is assigned, before any DB round
    // trip. $plan->archived_at is therefore already truncated in memory; $now (read straight
    // off freezeTime(), never passed through a date-cast setter) still carries them. A
    // microsecond-exact comparison would fail on that mismatch alone, not on the factory
    // writing the wrong value.
    $now = freezeTime();

    $plan = Plan::factory()->archived()->create();

    expect($plan->status)->toBe(PlanStatus::Archived->value);
    expect($plan->archived_at)->not->toBeNull('the factory must stamp archived_at, not merely leave status archived');

    // Real narrowing, not relied on from the expect() above: Pest's assertion is a runtime
    // check the static analyser cannot see through, so archived_at stays Carbon|null to
    // PHPStan and ->format() below needs this `if` to be level-8 clean. Unreachable at
    // runtime - the expect() above already failed the test if archived_at were null.
    if ($plan->archived_at === null) {
        throw new RuntimeException('unreachable: the expect() above already failed the test.');
    }

    expect($plan->archived_at->format('Y-m-d H:i:s'))->toBe($now->format('Y-m-d H:i:s'));
});

it('never produces an active membership without a joined_at', function (): void {
    // S-37: plan_members.joined_at is NULLable, in no index and under no CHECK, and the
    // members list's cursor leads on it - a keyset predicate over a NULL evaluates to NULL, so
    // such a row appears on page 1 and is dropped from every page after it. The application's
    // own writers always set joined_at (§7.2 step 2, §10.7 step 7); the factory is a writer
    // too, and its default is 'joined_at' => null with 'status' => invited, so overriding only
    // `status` to active is how that row gets made by accident.
    //
    // Every construction path this suite has, listed rather than discovered - and
    // 'covers every state PlanMemberFactory declares' (below, in this same file) is the
    // tripwire that keeps this list honest: it derives the factory's state methods from the
    // class itself and fails unless the set is exactly the four this list covers, so a fifth
    // state cannot be added without an author reading these lines.
    $plan = Plan::factory()->create();
    $user = User::factory()->hasProfile()->create();

    $rows = [
        'definition' => PlanMember::factory()->create(),
        'active' => PlanMember::factory()->active()->create(),
        'left' => PlanMember::factory()->left()->create(),
        'removed' => PlanMember::factory()->removed()->create(),
        'member() helper' => member($plan, $user, MemberRole::Member, '2026-01-01 10:00:00'),
    ];

    // The `if` guard means this loop only asserts on the two rows whose status IS active
    // ('active' and 'member() helper') - 'definition', 'left' and 'removed' run the loop body
    // and assert nothing, by construction, because their status correctly is not active. That
    // is deliberate, not slack: this test's job is "active implies joined_at", not "every row
    // has a joined_at", so a status that is not active is correctly a no-op here. The gap it
    // leaves is that a mutation making active() stop writing `status = active` would make the
    // `if` never fire for that row too, and this loop alone would go GREEN on a broken
    // active() rather than red - 'writes the status each state names, and demotes role only
    // where the endpoint does' (below) is what actually pins active()'s status, and the two
    // tests are a pair for that reason, not by accident.
    foreach ($rows as $label => $row) {
        if ($row->status === MemberStatus::Active->value) {
            expect($row->joined_at)->not->toBeNull($label);
        }
    }
});

it('covers every state PlanMemberFactory declares', function (): void {
    // The $rows list in 'never produces an active membership without a joined_at' (above, in
    // this same file) is maintained by hand, which is the kind of enumeration this project has
    // previously let drift from the thing it enumerates. This is the tripwire. The set of
    // states is DERIVED - PlanMemberFactory's public methods, minus the ones the Illuminate
    // Factory base class already declares, minus definition() - and compared against the
    // states $rows constructs a row from. A fifth state turns THIS test red, which is where its
    // author learns that the test named above needs a row for it.
    //
    // Measured, before step A11 adds the states:
    //
    //   $ /opt/homebrew/opt/php/bin/php -r 'require "vendor/autoload.php";
    //     var_dump(array_values(array_diff(
    //       get_class_methods(Database\Factories\PlanMemberFactory::class),
    //       get_class_methods(Illuminate\Database\Eloquent\Factories\Factory::class),
    //       ["definition"])));'
    //   array(0) {
    //   }
    //
    // and the same probe reports 55 public methods on the base Factory, none of them named
    // active, left, removed or role - so after A11 this list is exactly those four. Note what
    // the diff buys: a future Illuminate release that added its own Factory::active() would
    // subtract our state from the derived set and turn this test RED, rather than quietly
    // leaving one state uncovered.
    //
    // PlanFactory is deliberately not covered here. Its one state carries no cross-page
    // invariant, while PlanMemberFactory's states are what keep S-37's
    // `status = active, joined_at = NULL` row out of every fixture in the suite.
    //
    // Nor is PlanInviteFactory, and that gap is wider and worth naming rather than leaving
    // silent: its five states (expired(), revoked(), accepted(), linkOnly(), forToken()) have
    // zero call sites anywhere in app/ or tests/ as of this task - none of them back a cross-row
    // invariant the way PlanMemberFactory's four do, so there is no S-37-shaped drift for a
    // tripwire here to catch yet. forToken() is the one exception worth flagging by name: its
    // contract is that hash('sha256', $plainToken) here must agree with whatever the real token
    // issuer hashes, and that pairing is unprovable until the task that writes the issuer
    // exists - this task inherits no obligation to prove it, but whichever task ships the
    // issuer does.
    $declared = array_values(array_diff(
        get_class_methods(PlanMemberFactory::class),
        get_class_methods(Factory::class),
        ['definition'],
    ));

    sort($declared);

    expect($declared)->toBe(['active', 'left', 'removed', 'role']);
});

it('writes the status each state names, and demotes role only where the endpoint does', function (): void {
    // §8.3: LeavePlan writes `left` and leaves role alone; RemoveMember writes `removed` AND
    // demotes role to member, "because a removed admin whose row still says admin is a row
    // claiming authority its status denies". The states mirror the endpoints, not each other.
    expect(PlanMember::factory()->active()->create()->status)->toBe(MemberStatus::Active->value);

    $left = PlanMember::factory()->role(MemberRole::Admin)->left()->create();

    expect($left->status)->toBe(MemberStatus::Left->value);
    expect($left->role)->toBe(MemberRole::Admin->value);

    $removed = PlanMember::factory()->role(MemberRole::Admin)->removed()->create();

    expect($removed->status)->toBe(MemberStatus::Removed->value);
    expect($removed->role)->toBe(MemberRole::Member->value);
});

it('writes a role from the enum rather than a literal', function (): void {
    $owner = PlanMember::factory()->role(MemberRole::Owner)->create();

    expect($owner->role)->toBe(MemberRole::Owner->value);
});
