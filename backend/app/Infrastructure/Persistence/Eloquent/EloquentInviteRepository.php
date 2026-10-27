<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Plans\InvitePreview;
use App\Application\Plans\InviteRepository;
use App\Application\Plans\PlanInviteNotFound;
use App\Application\Plans\PlanInviteView;
use App\Domain\Plans\MemberStatus;
use App\Models\PlanInvite;
use Illuminate\Support\Facades\DB;
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

    public function findPreviewByToken(string $plainToken): ?InvitePreview
    {
        $row = DB::table('plan_invites')
            ->join('plans', 'plans.id', '=', 'plan_invites.plan_id')
            // leftJoin, never join: S-3. invited_by is SET NULL by the day-30 purge, and an
            // inner join would make a purged invite's preview disappear instead of rendering
            // inviter_name: null - the wrong failure for an unauthenticated caller who followed
            // an otherwise still-valid link.
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'plan_invites.invited_by')
            // R6: plaintext-in, hashed inside the repository - no caller ever forms the hash.
            ->where('plan_invites.token_hash', '=', hash('sha256', $plainToken))
            ->select(
                'plans.id as plan_id',
                'plans.title as plan_title',
                'user_profiles.display_name as inviter_name',
            )
            // A correlated count, not either of the Plan repository's own two active-member
            // counts: see InvitePreview's and InviteRepository's own docblocks for the
            // disclosed three-way duplication.
            ->selectSub(
                fn ($query) => $query->selectRaw('count(*)')
                    ->from('plan_members')
                    ->whereColumn('plan_members.plan_id', 'plans.id')
                    ->where('plan_members.status', MemberStatus::Active->value),
                'member_count',
            )
            ->first();

        if ($row === null) {
            return null;
        }

        return new InvitePreview(
            planId: $row->plan_id,
            planTitle: $row->plan_title,
            inviterName: $row->inviter_name,
            memberCount: (int) $row->member_count,
        );
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
            // is_string() rather than a cast - and NOT because a bare (string) on a null here
            // would warn: (string) null is silent and yields '', so neither an E_WARNING nor a
            // 500 is reachable on a leftJoin miss. (That hazard is real for
            // AppServiceProvider::boot()'s 'auth-forgot-password' closure, but what reaches it
            // is an ARRAY from request input, not a null from a join - a different value, not
            // the same pattern.)
            //
            // Measured, not assumed: deleting this check and passing $name straight into
            // fromInvite()'s ?string parameter is errors: 0 at this project's PHPStan level 8,
            // both file-scoped and project-wide - phpstan.neon enables no checkExplicitMixed and
            // no strict-rules/bleeding-edge extension, so level 8 here does not hold a mixed
            // value passed into a typed parameter open at all, despite getAttribute() genuinely
            // returning mixed (\PHPStan\dumpType() on $name confirms that half). It is also
            // behaviourally identical to today's check for every row this query can produce -
            // invited_by_name is always string|null (a LEFT JOIN on a NOT NULL display_name
            // column), and a null passed through raw is still null - and the whole focused test
            // file passes either way.
            //
            // What this check actually holds open: against a bare (string) cast, not against
            // passing $name through unchecked, it is load-bearing. (string) $name collapses a
            // missing profile's null to '', where baseline S-3 requires null, and that is
            // exactly what the hard-deleted-inviter test catches ("Failed asserting that '' is
            // null."). Read this as documentation that forecloses a future cast, not as
            // something an analyser or today's behaviour requires.
            $views[] = PlanInviteView::fromInvite($row, is_string($name) ? $name : null);
        }

        return $views;
    }
}
