<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Clock;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Spec §9.3 step 5: hard-delete accounts soft-deleted more than the grace window ago.
 *
 * This command deliberately does not touch `groups.owner_id`. That column is also NOT NULL
 * with RESTRICT, but every soft-deleted user that can ever reach this job got here through
 * App\Application\Users\DeleteAccount, whose step 3 already transferred or deleted every
 * Group they owned before step 5 soft-deleted them - see resolveGroupOwnership() there. If
 * that invariant is ever broken (a future bug, or a Group endpoint shipped without updating
 * DeleteAccount - see the warning in 2026_10_06_000200_create_plan_tables.php), the
 * forceDelete() below throws a QueryException and the run for that user aborts rather than
 * reporting a quiet "purged" for a row that was not actually removed. See
 * tests/Feature/Users/PurgeDeletedUsersTest.php's "relies on account deletion having already
 * resolved group ownership" case, which pins exactly that failure mode.
 */
final class PurgeDeletedUsersCommand extends Command
{
    public const GRACE_DAYS = 30;

    protected $signature = 'users:purge-deleted {--grace-days= : Override the grace window in days}';

    protected $description = 'Hard-delete accounts soft-deleted more than the grace window ago (spec §9.3 step 5).';

    public function handle(Clock $clock): int
    {
        $graceDays = (int) ($this->option('grace-days') ?? self::GRACE_DAYS);
        $cutoff = $clock->now()->modify("-{$graceDays} days");

        $users = User::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        $purged = 0;

        foreach ($users as $user) {
            DB::transaction(function () use ($user): void {
                // Must precede the user's hard delete: plans.owner_id is NOT NULL with
                // RESTRICT, so Postgres refuses to remove a user who still owns a Plan. Every
                // Plan still owned by this user at this point is, by construction, one that
                // branch 3 of §9.3 step 2 archived because there was no successor - transfers
                // already moved owner_id elsewhere.
                //
                // This is a hard delete, not Plan::query()->...->delete() being mistaken for
                // one: Plan does NOT use SoftDeletes, so ->delete() already removes the row.
                // If a later task adds SoftDeletes to Plan, this line must become
                // forceDelete() or the purge silently stops working while still reporting
                // success - see tests/Feature/Users/PurgeDeletedUsersTest.php's Step 5 proof
                // that commenting this line out fails loudly (a Postgres FK violation on
                // plans_owner_id_foreign), not quietly.
                Plan::query()->where('owner_id', $user->id)->delete();

                $user->forceDelete();
            });

            $purged++;
        }

        $this->line(json_encode([
            'tool' => 'users:purge-deleted',
            'purged' => $purged,
            'grace_days' => $graceDays,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
