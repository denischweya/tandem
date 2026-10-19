<?php

declare(strict_types=1);

use App\Application\Plans\InvitePreview;
use App\Application\Plans\IssuedInvite;
use App\Application\Plans\PlanInviteView;
use App\Domain\Plans\MemberRole;
use App\Http\Resources\Api\V1\Plans\InvitePreviewResource;
use App\Http\Resources\Api\V1\Plans\PlanInviteCreatedResource;
use App\Http\Resources\Api\V1\Plans\PlanInviteResource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// tests/Unit boots no application (tests/Pest.php's single uses(TestCase::class)->in('Feature')
// line names Feature only), so every DTO below is built with `new`, never via PlanInvite::
// factory()->make() or a fromInvite() call: Factory::expandAttributes() resolves a nested
// factory default by calling ->create() regardless of make() versus create(), which would drag
// Plan::factory(), User::factory() and UserFactory::definition()'s Hash::make() into this
// unbooted process (measured on Task 12, reproduced here rather than re-measured). Coverage of
// IssuedInvite::fromInvite() itself lives in tests/Feature/Application/Plans/IssuedInviteTest.php
// instead, forced by the same call: it reads $invite->expires_at, ->accepted_at and ->revoked_at,
// and a model's datetime cast needs a resolved connection on both the write and the read side
// (Task 12's measured reason), which this suite never boots.
//
// accepted_at and revoked_at below are two DIFFERENT non-null timestamps, not both null: both
// are typed ?Carbon on every DTO in this file, and a fixture leaving them equal (null or
// otherwise) cannot tell a correct mapping from one that swapped the two - exactly the shape
// Task 10 shipped wrong and twelve passing tests never caught (task-14-context.md §1c).
it('renders PlanInviteResource with no token key at all', function (): void {
    $summary = new PlanInviteView(
        id: '0192f0a1-0000-7000-8000-000000000005',
        email: 'invitee@example.test',
        role: MemberRole::Member,
        expiresAt: Carbon::parse('2026-10-20 00:00:00+00:00'),
        acceptedAt: Carbon::parse('2026-10-21 00:00:00+00:00'),
        revokedAt: Carbon::parse('2026-10-22 00:00:00+00:00'),
        invitedByName: 'Ada Lovelace',
    );

    $rendered = (new PlanInviteResource($summary))->toArray(new Request);

    expect($rendered)->not->toHaveKey('token');
    // Milliseconds, not the brief's literal: DATE_RFC3339_EXTENDED is 'Y-m-d\TH:i:s.vP' and
    // measured (resources-inherited-rules.md D-R4 probe) to always render three millisecond
    // digits, even for a zero-microsecond instant - '...T00:00:00+00:00' (no .000) is not a
    // string this format call can produce.
    expect($rendered)->toBe([
        'id' => '0192f0a1-0000-7000-8000-000000000005',
        'email' => 'invitee@example.test',
        'role' => 'member',
        'expires_at' => '2026-10-20T00:00:00.000+00:00',
        'accepted_at' => '2026-10-21T00:00:00.000+00:00',
        'revoked_at' => '2026-10-22T00:00:00.000+00:00',
        'invited_by_name' => 'Ada Lovelace',
    ]);
});

// Not the brief's PlanInvite::factory()->make() + PlanInviteView::fromInvite() fixture - see the
// file banner above. This still exercises the one thing this test is actually for, which is the
// Resource reading a null invitedByName straight through, without needing the model or
// fromInvite() at all.
it('renders invited_by_name null for an invite with no resolvable inviter (S-3)', function (): void {
    $summary = new PlanInviteView(
        id: '0192f0a1-0000-7000-8000-000000000006',
        email: 'other-invitee@example.test',
        role: MemberRole::Member,
        expiresAt: Carbon::parse('2026-10-20 00:00:00+00:00'),
        acceptedAt: null,
        revokedAt: null,
        invitedByName: null,
    );

    expect((new PlanInviteResource($summary))->toArray(new Request)['invited_by_name'])->toBeNull();
});

// Not the brief's PlanInvite::factory()->make() + IssuedInvite::fromInvite() fixture - see the
// file banner above. Built directly so this stays a pure Resource test; fromInvite()'s own
// mapping is proven in tests/Feature/Application/Plans/IssuedInviteTest.php.
//
// Every one of IssuedInvite's eight properties gets its own, obviously distinguishable value:
// id vs. token (both string), email vs. invitedByName (both ?string), and acceptedAt vs.
// revokedAt (both ?Carbon) - task-14-context.md §1c's three pairs for this class. A single
// toBe() over the whole rendered array is still one assertion, but PHPUnit's array comparator
// diffs every differing key at once - measured directly against this project's own PHPUnit, by
// running a scratch comparison of two same-shaped arrays differing in two keys: the failure
// message's diff names both differing keys, not only the first. So a swap of any one pair below
// shows up in that one diff without needing a second, narrowed mutation to reach it - unlike the
// chained-assertion shape Task 13 needed two mutations per pair to clear.
it('renders PlanInviteCreatedResource with the token, exactly once', function (): void {
    $summary = new IssuedInvite(
        id: '0192f0a1-0000-7000-8000-000000000007',
        email: 'invitee2@example.test',
        role: MemberRole::Admin,
        expiresAt: Carbon::parse('2026-10-20 00:00:00+00:00'),
        acceptedAt: Carbon::parse('2026-10-21 00:00:00+00:00'),
        revokedAt: Carbon::parse('2026-10-22 00:00:00+00:00'),
        invitedByName: 'Ada Lovelace',
        token: 'plaintext-token-value',
    );

    $rendered = (new PlanInviteCreatedResource($summary))->toArray(new Request);

    expect($rendered['token'])->toBe('plaintext-token-value');
    expect($rendered)->toBe([
        'id' => '0192f0a1-0000-7000-8000-000000000007',
        'email' => 'invitee2@example.test',
        'role' => 'admin',
        'expires_at' => '2026-10-20T00:00:00.000+00:00',
        'accepted_at' => '2026-10-21T00:00:00.000+00:00',
        'revoked_at' => '2026-10-22T00:00:00.000+00:00',
        'invited_by_name' => 'Ada Lovelace',
        'token' => 'plaintext-token-value',
    ]);
});

it('renders InvitePreviewResource with no email and no token at all', function (): void {
    $summary = new InvitePreview(
        planId: '0192f0a1-0000-7000-8000-000000000008',
        planTitle: 'Camping trip',
        inviterName: null,
        memberCount: 4,
    );

    $rendered = (new InvitePreviewResource($summary))->toArray(new Request);

    expect($rendered)->toBe([
        'plan_id' => '0192f0a1-0000-7000-8000-000000000008',
        'plan_title' => 'Camping trip',
        'inviter_name' => null,
        'member_count' => 4,
    ]);
});
