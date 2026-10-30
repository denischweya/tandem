<?php

declare(strict_types=1);

namespace App\Application\Plans;

use DateTimeImmutable;

/**
 * The flat, Resource-ready read model for one message.
 *
 * userId is nullable since ADR 0012: account deletion anonymises plan_messages.user_id rather
 * than deleting the row, so an authorless message is a real state every reader carries - and
 * the one the author-only edit and delete gates refuse for everybody, because a null id is
 * never equal to an actor's.
 *
 * userDisplayName is resolved outside this class - by the repository, from a batched
 * user_profiles lookup - so there is nowhere here for a per-row N+1 to hide, exactly as
 * PlanActivitySummary::$actorName records for the same table. It is null for TWO reasons a
 * client cannot tell apart and does not need to: an anonymised author (no userId at all), or a
 * userId with no matching user_profiles row. The second is the narrower of the two -
 * user_profiles.user_id is UNIQUE NOT NULL and registration is the only path that creates a
 * user - but the hard purge cascades the profile away, so it is representable rather than
 * merely defensive.
 */
final readonly class PlanMessageSummary
{
    /** @param list<PlanMessageReactionSummary> $reactions */
    public function __construct(
        public string $id,
        public string $planId,
        public ?string $userId,
        public ?string $userDisplayName,
        public string $body,
        public ?DateTimeImmutable $editedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $reactions,
    ) {}
}
