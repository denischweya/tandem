<?php

declare(strict_types=1);

use App\Application\Plans\PlanActivitySummary;
use App\Domain\Plans\ActivityType;
use App\Http\Resources\Api\V1\Plans\PlanActivityResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

it('renders every published field, with activity_type as its string value', function (): void {
    // Context file §2: PlanActivitySummary has three invisible same-typed swap pairs
    // (actorId<->actorName, subjectType<->subjectId, actorId<->subjectId). The brief's own
    // fixture leaves subjectId identical to actorId ('...002' for both), which would not
    // notice a transposition between those two. subjectId is given a third, distinct uuid
    // here so a swap on any of the three pairs changes the expected array.
    $summary = new PlanActivitySummary(
        id: '0192f0a1-0000-7000-8000-000000000003',
        activityType: ActivityType::MemberJoined,
        actorId: '0192f0a1-0000-7000-8000-000000000002',
        actorName: 'Ada Lovelace',
        subjectType: 'User',
        subjectId: '0192f0a1-0000-7000-8000-000000000005',
        metadata: ['role' => 'member'],
        createdAt: Carbon::parse('2026-10-05 09:00:00+00:00'),
    );

    expect((new PlanActivityResource($summary))->toArray(new Request))->toBe([
        'id' => '0192f0a1-0000-7000-8000-000000000003',
        'activity_type' => 'member.joined',
        'actor_id' => '0192f0a1-0000-7000-8000-000000000002',
        'actor_name' => 'Ada Lovelace',
        'subject_type' => 'User',
        'subject_id' => '0192f0a1-0000-7000-8000-000000000005',
        'metadata' => ['role' => 'member'],
        // Milliseconds, not the brief's literal: DATE_RFC3339_EXTENDED is 'Y-m-d\TH:i:s.vP'
        // (lowercase v) and always renders three millisecond digits, even for a
        // zero-microsecond Carbon instant - '2026-10-05T09:00:00+00:00' (no .000) is not a
        // string this format can produce. plan-corrections.md Part C item 6 names this task's
        // D.1/D.4 pair by name; fixed here against the measured output, matching PlanResource
        // (Task 11) and PlanMemberResource (Task 12) rather than weakening the format() call.
        'created_at' => '2026-10-05T09:00:00.000+00:00',
    ]);
});

it('renders a null actor_name for an anonymised row with no actor_id at all', function (): void {
    $summary = new PlanActivitySummary(
        id: '0192f0a1-0000-7000-8000-000000000004',
        activityType: ActivityType::PlanCreated,
        actorId: null,
        actorName: null,
        subjectType: null,
        subjectId: null,
        metadata: [],
        createdAt: Carbon::parse('2026-10-05 09:00:00+00:00'),
    );

    expect((new PlanActivityResource($summary))->toArray(new Request))
        ->toMatchArray(['actor_id' => null, 'actor_name' => null]);
});
