<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();

// Spec §9.3 step 5. Daily, not more often: the grace window is measured in days, and a
// soft-deleted row sitting for a few extra hours before the next run costs nothing.
Schedule::command('users:purge-deleted')->dailyAt('03:00')->withoutOverlapping();
