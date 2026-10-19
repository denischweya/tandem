<?php

declare(strict_types=1);

namespace App\Application\Plans;

/**
 * Deliberately minimal, per blueprint §126 and §154: the Plan's title, the inviter's display
 * name, and a count. No member list, no description, no email address, no invitee identity, no
 * Plan status beyond its usability. GET /invites/{token} is the only unauthenticated read in
 * the API that touches Plan data, so every field here is a field disclosed to anyone holding a
 * link.
 *
 * inviterName is nullable because plan_invites.invited_by is nullable with nullOnDelete, fired
 * by the day-30 purge - so a purged inviter's invite still has a preview, just one with no
 * name. The leftJoin in findPreviewByToken() is what turns that into inviter_name: null rather
 * than the whole preview disappearing (S-3, the same nullability PlanInviteView rests on).
 *
 * memberCount counts status = active rows only, matching GET /plans/{plan}/members. It is
 * computed by this repository's own query rather than reused from elsewhere - the THIRD
 * independent "count a Plan's active members" implementation in this codebase, alongside the
 * Plan repository's own existence-oracle count and its authenticated member-count aggregate.
 * That is a disclosed duplication, not an oversight - see findPreviewByToken()'s own docblock
 * for which places a future ruling about member eligibility would need to change together.
 */
final readonly class InvitePreview
{
    public function __construct(
        public string $planId,
        public string $planTitle,
        public ?string $inviterName,
        public int $memberCount,
    ) {}
}
