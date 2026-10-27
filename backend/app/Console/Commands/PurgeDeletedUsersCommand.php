<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Plans\PlanStatus;
use App\Domain\Shared\Clock;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
                    //
                    // The status predicate is the whole scope of this statement, not a
                    // refinement of it. Spec §9.3 authorises hard-deleting "the user's
                    // ARCHIVED sole-owned Plans" - the ones branch 3 of step 2 archived
                    // because no successor existed - and nothing wider. Without it this
                    // deleted every Plan the column still pointed at, including an `active`
                    // Plan with live members, taking plan_members, plan_invites and
                    // plan_activity with it through CASCADE and reporting the user purged.
                    // Measured, not feared: one active Plan with one live active member went
                    // plans 1->0 and plan_members 1->0 while the command exited 0.
                    //
                    // What is left behind is deliberately a LOUD failure rather than a
                    // narrower delete: an active Plan owned by an overdue soft-deleted user
                    // is a broken invariant (step 2 transfers ownership of any Plan with a
                    // remaining active member), and RESTRICT on plans.owner_id turns it into
                    // a per-user failure this run records and exits non-zero for - exactly
                    // what resolveGroupOwnership()'s equivalent gap already gets, as the
                    // class docblock describes. Silently destroying the Plan instead was the
                    // only place in this method that chose differently.
                    //
                    // This is a hard delete, not Plan::query()->...->delete() being mistaken
                    // for one: Plan does NOT use SoftDeletes, so ->delete() already removes
                    // the row. If a later task adds SoftDeletes to Plan, this line must
                    // become forceDelete() or the purge silently stops working while still
                    // reporting success - see tests/Feature/Users/PurgeDeletedUsersTest.php's
                    // "hard-deletes the archived plan the departing user still solely owned",
                    // which fails with a Postgres FK violation on plans_owner_id_foreign if
                    // this statement stops removing rows.
                    Plan::query()
                        ->where('owner_id', $user->id)
                        ->where('status', PlanStatus::Archived->value)
                        ->delete();

                    $user->forceDelete();
                });
            } catch (Throwable $e) {
                // Caught per user, deliberately. Without this catch a single row that violates
                // a database invariant (e.g. still owning a Group, or an active Plan - see the
                // class doc and the status predicate above) aborts the whole run on the first
                // bad row it reaches. Combined with the deterministic ORDER BY above that is
                // worse than nondeterministic, not better: the same row leads the queue at
                // every 03:00 run, so everyone behind it would never be purged at all.
                // DB::transaction() has already rolled back whatever this user's attempt
                // touched, so moving on costs nothing but this one row's progress today.
                //
                // Logged as well as printed. ->components->warn() writes to the command's
                // output, which under the scheduler goes nowhere (routes/console.php routes
                // only the failure hook, and a scheduler ignores exit codes), so the log line
                // is the only durable record that a specific user is stuck. Structured with a
                // stable `event` key so it is greppable and alertable; the user id and the
                // driver's message, and nothing else - no token material passes through here,
                // and the exception message is the driver's, not a trace, for the same reason
                // OutboxDispatcher::recordFailure() stores only the message.
                $this->components->warn("users:purge-deleted: failed to purge user {$user->id}: {$e->getMessage()}");

                Log::warning('users.purge_deleted.user_failed', [
                    'event' => 'users.purge_deleted.user_failed',
                    'user_id' => $user->id,
                    'reason' => $e->getMessage(),
                ]);

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
     * The option is matched WHOLE before it is cast, because (int) parses a prefix and
     * discards the rest without complaint. `--grace-days=3O` - a letter O where a zero was
     * meant, the obvious typo for 30 - is (int) 3, so it silently purged accounts 27 days
     * early; `--grace-days=1.9` is 1, and `--grace-days=abc` is 0, which then fell through
     * into the --force branch below and reported a problem the operator did not have.
     * Measured on all three. An earlier fix here guarded the resolved integer's SIGN and not
     * the parse, which closed "-5" and left the whole class open. ctype_digit() is the check
     * because the option is only ever legitimately a run of decimal digits: no sign (handled
     * separately below so a negative keeps its own, more specific message), no exponent, no
     * whitespace, no hex.
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
     * forbidden outright - but a bare --grace-days=0 must not trigger it by accident. A typo
     * that drops the value entirely no longer reaches this branch at all: '' is not a digit
     * run, so it is refused above with the message that actually describes it.
     */
    private function resolveGraceDays(): ?int
    {
        $option = $this->option('grace-days');

        if ($option === null) {
            return self::GRACE_DAYS;
        }

        // (string) deliberately, and not because PHPStan asks for it - it already narrows
        // this to string. Symfony's ArrayInput, which both `php artisan --grace-days=30` and
        // Pest\Laravel\artisan('...', ['--grace-days' => 30]) end up building, stores whatever
        // the caller supplied without coercing it, so an integer option genuinely arrives here
        // and str_starts_with() on one is a TypeError under declare(strict_types=1).
        $raw = (string) $option;
        $digits = str_starts_with($raw, '-') ? substr($raw, 1) : $raw;

        if (! ctype_digit($digits)) {
            $this->components->error("--grace-days must be a whole number of days (got \"{$raw}\").");

            return null;
        }

        $graceDays = (int) $raw;

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
