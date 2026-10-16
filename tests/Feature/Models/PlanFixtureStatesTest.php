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

uses(RefreshDatabase::class);

it('archives a Plan the way the application does, both fields', function (): void {
    // DeleteAccount.php:160-164 and §7.4 both write status AND archived_at. A fixture that
    // writes only status produces a shape no code path produces, and PlanTest.php:102 is
    // exactly that fixture.
    $plan = Plan::factory()->archived()->create();

    expect($plan->status)->toBe(PlanStatus::Archived->value);
    expect($plan->archived_at)->not->toBeNull();
});

it('never produces an active membership without a joined_at', function (): void {
    // S-37: plan_members.joined_at is NULLable, in no index and under no CHECK, and the
    // members list's cursor leads on it - a keyset predicate over a NULL evaluates to NULL, so
    // such a row appears on page 1 and is dropped from every page after it. The application's
    // own writers always set joined_at (§7.2 step 2, §10.7 step 7); the factory is a writer
    // too, and its default is 'joined_at' => null with 'status' => invited, so overriding only
    // `status` to active is how that row gets made by accident.
    //
    // Every construction path this suite has, listed rather than discovered - and the test
    // directly below is the tripwire that keeps this list honest: it derives the factory's
    // state methods from the class itself and fails unless the set is exactly the four this
    // list covers, so a fifth state cannot be added without an author reading these lines.
    $plan = Plan::factory()->create();
    $user = User::factory()->hasProfile()->create();

    $rows = [
        'definition' => PlanMember::factory()->create(),
        'active' => PlanMember::factory()->active()->create(),
        'left' => PlanMember::factory()->left()->create(),
        'removed' => PlanMember::factory()->removed()->create(),
        'member() helper' => member($plan, $user, MemberRole::Member, '2026-01-01 10:00:00'),
    ];

    foreach ($rows as $label => $row) {
        if ($row->status === MemberStatus::Active->value) {
            expect($row->joined_at)->not->toBeNull($label);
        }
    }
});

it('covers every state PlanMemberFactory declares', function (): void {
    // The $rows list in the test above is maintained by hand, which is the kind of enumeration
    // this project has previously let drift from the thing it enumerates. This is the tripwire.
    // The set of states is DERIVED - PlanMemberFactory's public methods, minus the ones the
    // Illuminate Factory base class already declares, minus definition() - and compared against
    // the states $rows constructs a row from. A fifth state turns THIS test red, which is where
    // its author learns that the test above needs a row for it.
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
