<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();

// Spec §9.3 step 5. Daily, not more often: the grace window is measured in days, and a
// soft-deleted row sitting for a few extra hours before the next run costs nothing.
//
// onFailureWithOutput, and not a bare ->onFailure(): PurgeDeletedUsersCommand deliberately
// exits FAILURE when any user could not be purged (see the contrast with outbox:dispatch
// recorded at the end of its handle()), and that exit code went nowhere. A scheduler does
// not read exit codes, and the command's own ->components->warn() for each stuck user wrote
// to an output stream the scheduler discards - so the careful divergence from
// outbox:dispatch bought nothing at all. ensureOutputIsBeingCaptured() (reached through
// onFailureWithOutput) redirects the run's stdout to a file and hands it to this callback,
// which is what makes the per-user warnings and the JSON summary readable after the fact.
//
// Log, not ->emailOutputTo(): mail leaves this application through the outbox
// (OutboxDispatcher -> the handlers in AppServiceProvider), so a failure notice sent by mail
// travels through machinery that may itself be what is broken - and the outbox's own wedged
// -row condition is reported the same way, by OutboxDispatcher, for the same reason.
//
// The command already logs one line per stuck user with a `user_id`; this adds the one line
// that says the RUN failed, with the captured output attached, so an alert can fire on
// either. No token material reaches either line.
Schedule::command('users:purge-deleted')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailureWithOutput(function (Stringable $output): void {
        Log::error('users.purge_deleted.run_failed', [
            'event' => 'users.purge_deleted.run_failed',
            'output' => $output->trim()->value(),
        ]);
    });
