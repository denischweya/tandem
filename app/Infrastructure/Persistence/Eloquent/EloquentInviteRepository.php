<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Plans\InviteRepository;
use App\Application\Plans\PlanInviteNotFound;
use App\Application\Plans\PlanInviteView;
use App\Models\PlanInvite;
use Illuminate\Support\Str;

final readonly class EloquentInviteRepository implements InviteRepository
{
    public function findForPlan(string $planId, string $inviteId): PlanInvite
    {
        // §5.1's third identifier. Without the shape check, `where id = 'not-a-uuid'` against a
        // uuid column is Postgres 22P02 and surfaces as a 500 on an authenticated route.
        if (! Str::isUuid($planId) || ! Str::isUuid($inviteId)) {
            throw new PlanInviteNotFound;
        }

        // plan_id in the predicate, not checked after the fetch: §17.3's third IDOR row is an
        // {invite} that belongs to another Plan, and a check applied afterwards is a check
        // someone edits away.
        $invite = PlanInvite::query()
            ->where('plan_id', $planId)
            ->whereKey($inviteId)
            ->first();

        if ($invite === null) {
            throw new PlanInviteNotFound;
        }

        return $invite;
    }

    public function findByToken(string $plainToken): ?PlanInvite
    {
        return PlanInvite::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
    }

    public function findByTokenForUpdate(string $plainToken): ?PlanInvite
    {
        return PlanInvite::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->lockForUpdate()
            ->first();
    }

    public function listForPlan(string $planId): array
    {
        if (! Str::isUuid($planId)) {
            return [];
        }

        $rows = PlanInvite::query()
            ->select('plan_invites.*')
            // One left join for the whole list rather than a query per row, and LEFT because
            // invited_by is nullable with SET NULL - the inviter may have been hard-deleted
            // under an invite this list still shows.
            ->addSelect('user_profiles.display_name as invited_by_name')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'plan_invites.invited_by')
            ->where('plan_invites.plan_id', $planId)
            // Qualified on both columns: user_profiles carries id, created_at and updated_at
            // too, so an unqualified name here would be ambiguous.
            ->orderByDesc('plan_invites.created_at')
            ->orderByDesc('plan_invites.id')
            ->get();

        $views = [];

        foreach ($rows as $row) {
            $name = $row->getAttribute('invited_by_name');

            // fromInvite(), not new PlanInviteView($row, ...): the view is seven typed scalars,
            // so the model stops here and nothing downstream can reach through it.
            //
            // is_string() rather than a cast, for two forces - and NOT because a bare (string)
            // on a null here would warn: (string) null is silent and yields '', so neither an
            // E_WARNING nor a 500 is reachable on a leftJoin miss. (That hazard is real for
            // AppServiceProvider::boot()'s 'auth-forgot-password' closure, but what reaches it
            // is an ARRAY from request input, not a null from a join - a different value, not
            // the same pattern.)
            //   1. getAttribute() returns mixed, so this is what narrows it to ?string before
            //      PlanInviteView::fromInvite() can accept it - held open by PHPStan level 8.
            //   2. A (string) cast would publish '' where baseline S-3 requires null, silently
            //      collapsing the exact case the hard-deleted-inviter test exists to prove.
            $views[] = PlanInviteView::fromInvite($row, is_string($name) ? $name : null);
        }

        return $views;
    }
}
