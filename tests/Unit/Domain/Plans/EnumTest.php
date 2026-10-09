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

it('agrees with the column defaults the migrations set', function (): void {
    expect(MemberRole::Member->value)->toBe('member');
    expect(MemberStatus::Invited->value)->toBe('invited');
    expect(PlanStatus::Active->value)->toBe('active');
    expect(Visibility::InviteOnly->value)->toBe('invite_only');
});
