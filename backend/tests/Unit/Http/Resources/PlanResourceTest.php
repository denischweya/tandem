<?php

declare(strict_types=1);

use App\Application\Plans\PlanSummary;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;
use App\Http\Resources\Api\V1\Plans\PlanResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

it('renders every published field, with enums as their string value', function (): void {
    $summary = new PlanSummary(
        id: '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f',
        title: 'Camping trip',
        description: null,
        status: PlanStatus::Active,
        visibility: Visibility::InviteOnly,
        timezone: 'Europe/London',
        ownerId: '0192f0a1-0000-7000-8000-000000000001',
        memberCount: 3,
        viewerRole: MemberRole::Admin,
        archivedAt: null,
        createdAt: Carbon::parse('2026-10-01 10:00:00+00:00'),
        updatedAt: Carbon::parse('2026-10-05 10:00:00+00:00'),
    );

    $rendered = (new PlanResource($summary))->toArray(new Request);

    expect($rendered)->toBe([
        'id' => '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f',
        'title' => 'Camping trip',
        'description' => null,
        'status' => 'active',
        'visibility' => 'invite_only',
        'timezone' => 'Europe/London',
        'owner_id' => '0192f0a1-0000-7000-8000-000000000001',
        'member_count' => 3,
        'viewer_role' => 'admin',
        'archived_at' => null,
        // Milliseconds, not the brief's literal: DATE_RFC3339_EXTENDED is 'Y-m-d\TH:i:s.vP'
        // (lowercase v), measured by probe to always render three millisecond digits, even
        // for a zero-microsecond Carbon instant - '2026-10-01T10:00:00+00:00' (no .000) is not
        // a string this format can produce. The brief's own literal here omitted them; fixed
        // against the measured output rather than weakening PlanResource's format() call,
        // which matches D-R4 and the shipped SessionResource precedent exactly.
        'created_at' => '2026-10-01T10:00:00.000+00:00',
        'updated_at' => '2026-10-05T10:00:00.000+00:00',
    ], 'status, visibility and viewer_role must publish ->value, never the enum itself - a '
        .'native enum on a Resource field publishes its class docblock as the schema '
        .'description under every enum_cases_description_strategy value (baseline S-9)');
});

it('publishes a non-null archived_at the same way every other timestamp is published', function (): void {
    $summary = new PlanSummary(
        id: '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f',
        title: 'Old trip',
        description: 'Done',
        status: PlanStatus::Archived,
        visibility: Visibility::Private,
        timezone: 'UTC',
        ownerId: '0192f0a1-0000-7000-8000-000000000001',
        memberCount: 1,
        viewerRole: MemberRole::Owner,
        archivedAt: Carbon::parse('2026-09-01 00:00:00+00:00'),
        createdAt: Carbon::parse('2026-08-01 00:00:00+00:00'),
        updatedAt: Carbon::parse('2026-09-01 00:00:00+00:00'),
    );

    expect((new PlanResource($summary))->toArray(new Request)['archived_at'])
        ->toBe('2026-09-01T00:00:00.000+00:00');
});
