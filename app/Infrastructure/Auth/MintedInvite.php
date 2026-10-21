<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Models\PlanInvite;

/**
 * What PlanInviteTokens::issue() returns, and it goes no further than the Application layer.
 * PasswordResetTokens::issue() returns a bare string because its one caller needs nothing but the
 * plaintext; an invite's caller needs the row too - plan_invites.id is the activity row's
 * subject_id and the outbox payload's invite_id - and the row cannot be looked up again except by
 * the hash it has just written, which would be a second query for a row already in hand.
 *
 * NAMED MintedInvite, NOT IssuedInvite, and that is a layering decision rather than a spelling
 * one. App\Application\Plans\IssuedInvite (shipped by the resources section) is the eight-scalar
 * value this mint result is mapped into by whichever task's use case writes a plan_invites row
 * through the invite surface, and it is the value PlanInviteCreatedResource mixes in. This class
 * carries an Eloquent model, including token_hash, so it must not be what a JsonResource reaches
 * through. The pair is exactly RefreshTokenIssuer::issue(): IssuedRefreshToken -> Login::execute():
 * Session in the shipped codebase: Infrastructure's own value object carries (plaintext, model)
 * and is referenced nowhere outside app/Infrastructure/Auth/, while the value that crosses into
 * Http is an Application one made of scalars.
 *
 * inviterName travels with it rather than being read back off a relation, because the inviter is
 * the ACTOR right there in issue()'s arguments. user_profiles.user_id is UNIQUE NOT NULL and
 * registration is the only path that creates a user, so the dereference needs no null check - the
 * same database-level invariant UserResource relies on.
 */
final readonly class MintedInvite
{
    public function __construct(
        public PlanInvite $invite,
        public string $token,
        public string $inviterName,
    ) {}
}
