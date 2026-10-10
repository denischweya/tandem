<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;

it('matches blueprint section 9 roles exactly', function (): void {
    expect(array_map(fn (MemberRole $r) => $r->value, MemberRole::cases()))
        ->toBe(['owner', 'admin', 'member']);
});

it('matches blueprint section 9 member statuses exactly', function (): void {
    expect(array_map(fn (MemberStatus $s) => $s->value, MemberStatus::cases()))
        ->toBe(['invited', 'active', 'left', 'removed']);
});

it('matches blueprint section 8 plan statuses exactly', function (): void {
    expect(array_map(fn (PlanStatus $s) => $s->value, PlanStatus::cases()))
        ->toBe(['active', 'archived', 'cancelled']);
});

it('matches blueprint section 8 visibility exactly, with no public option', function (): void {
    // "MVP should not support public Plans" - blueprint §8. A third case here would be a
    // product decision smuggled in as an enum.
    expect(array_map(fn (Visibility $v) => $v->value, Visibility::cases()))
        ->toBe(['private', 'invite_only']);
});

// The case that used to sit here - "agrees with the column defaults the migrations set" -
// read no migration and no column. It asserted four enum values against four literals, which
// is a restatement of the enum, not a comparison with anything: probe-changing both
// plans.status and plan_members.status defaults in the migration left the whole suite green.
//
// It has moved to tests/Feature/Schema/ConstraintTest.php, where it can read the defaults
// Postgres actually holds. That needs a database, and this file deliberately has none - spec
// §12 puts the domain enums in the unit suite precisely so they can be reasoned about without
// one, and tests/Pest.php binds TestCase to Feature only, so this file runs on plain PHPUnit.
