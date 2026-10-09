<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Clock;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Spec §9.3 step 5: hard-delete accounts soft-deleted more than the grace window ago.
 *
 * This command deliberately does not touch `groups.owner_id`. That column is also NOT NULL
 * with RESTRICT, but every soft-deleted user that can ever reach this job got here through
 * App\Application\Users\DeleteAccount, whose step 3 already transferred or deleted every
 * Group they owned before step 5 soft-deleted them - see resolveGroupOwnership() there. If
 * that invariant is ever broken (a future bug, or a Group endpoint shipped without updating
 * DeleteAccount - see the warning in 2026_10_06_000200_create_plan_tables.php), the
 * forceDelete() below throws, and the per-user catch in the loop below records it as a
 * failure rather than letting it abort every other purgeable user in the run - see that
 * catch for why. See tests/Feature/Users/PurgeDeletedUsersTest.php's "relies on account
 * deletion having already resolved group ownership" case, which pins exactly that failure
 * mode, and "continues purging healthy users...", which pins that it does not take anyone
 * else down with it.
 */
final class PurgeDeletedUsersCommand extends Command
{
    public const GRACE_DAYS = 30;

    protected $signature = 'users:purge-deleted
        {--grace-days= : Override the grace window in days}
        {--force : Required alongside --grace-days=0 to purge every soft-deleted account immediately}';

    protected $description = 'Hard-delete accounts soft-deleted more than the grace window ago (spec §9.3 step 5).';

    public function handle(Clock $clock): int
    {
        $graceDays = $this->resolveGraceDays();

        if ($graceDays === null) {
            return self::FAILURE;
        }

        $cutoff = $clock->now()->modify("-{$graceDays} days");

        // Ordered, not left to an unordered table scan's incidental ordering: the per-user
        // catch below means one failure no longer blocks the whole run, but it still has to
        // make deterministic progress - oldest-overdue first - rather than depend on
        // whichever order Postgres happens to return today.
        $users = User::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->orderBy('deleted_at')
            ->orderBy('id')
            ->get();

        $purged = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                DB::transaction(function () use ($user): void {
                    // Must precede the user's hard delete: plans.owner_id is NOT NULL with
                    // RESTRICT, so Postgres refuses to remove a user who still owns a Plan.
                    // Every Plan still owned by this user at this point is, by construction,
                    // one that branch 3 of §9.3 step 2 archived because there was no
                    // successor - transfers already moved owner_id elsewhere.
                    //
                    // This is a hard delete, not Plan::query()->...->delete() being mistaken
                    // for one: Plan does NOT use SoftDeletes, so ->delete() already removes
                    // the row. If a later task adds SoftDeletes to Plan, this line must
                    // become forceDelete() or the purge silently stops working while still
                    // reporting success - see tests/Feature/Users/PurgeDeletedUsersTest.php's
                    // Step 5 proof that commenting this line out fails loudly (a Postgres FK
                    // violation on plans_owner_id_foreign), not quietly.
                    Plan::query()->where('owner_id', $user->id)->delete();

                    $user->forceDelete();
                });
            } catch (Throwable $e) {
                // Caught per user, deliberately: the query above has no ORDER BY guarantee
                // strong enough to matter here anyway, because without this catch a single
                // row that violates a database invariant (e.g. still owning a Group - see
                // the class doc) would abort the whole run on the very first bad row it
                // hits, every single day, and nobody queued behind it would ever be purged.
                // DB::transaction() has already rolled back whatever this user's attempt
                // touched, so moving on costs nothing but this one row's progress today.
                $this->components->warn("users:purge-deleted: failed to purge user {$user->id}: {$e->getMessage()}");

                $failed++;

                continue;
            }

            $purged++;
        }

        $this->line(json_encode([
            'tool' => 'users:purge-deleted',
            'purged' => $purged,
            'failed' => $failed,
            'grace_days' => $graceDays,
        ], JSON_THROW_ON_ERROR));

        // Unlike outbox:dispatch (DispatchOutboxCommand), which returns SUCCESS even with
        // failures because a failed event simply stays pending and is retried next pass by
        // design, a failure here means a database invariant was violated and will not
        // self-heal on its own - the same row is still sitting there, unpurged, at the next
        // 03:00 run. That must stay visible rather than being "harmonised" with
        // outbox:dispatch's choice: the two commands fail differently because retrying is
        // the designed fix for one and never the fix for the other.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Returns null (having already printed the error) when the option is invalid, so
     * handle() can bail out before a cutoff is ever computed from it.
     *
     * Negative values are rejected outright, with no override: $clock->now()->modify("-{$graceDays}
     * days") builds that string literally, and a negative $graceDays doubles the leading
     * minus - "--5 days" for -5 - which PHP's relative-date parser resolves as "+5 days": a
     * WIDER purge than the default, silently, rather than an error. That is the worst
     * possible failure mode for the only irreversible command in this codebase, so it is
     * caught before the string is ever built rather than left to produce a cutoff nobody
     * asked for.
     *
     * Zero is rejected unless --force is also passed. "Purge everyone right now" is a
     * legitimate operator intent (e.g. acting on a compliance request), so it is not
     * forbidden outright - but a bare --grace-days=0, or a typo that drops the value
     * entirely ((int) '' is also 0), must not trigger it by accident.
     */
    private function resolveGraceDays(): ?int
    {
        $option = $this->option('grace-days');
        $graceDays = $option === null ? self::GRACE_DAYS : (int) $option;

        if ($graceDays < 0) {
            $this->components->error("--grace-days must not be negative (got {$graceDays}).");

            return null;
        }

        if ($graceDays === 0 && ! $this->option('force')) {
            $this->components->error('--grace-days=0 purges every soft-deleted account immediately. Pass --force to confirm this is intended.');

            return null;
        }

        return $graceDays;
    }
}
