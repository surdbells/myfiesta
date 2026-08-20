<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Reminders.
 *
 * Every fifteen minutes rather than hourly: a "one hour before" reminder that
 * can be up to an hour late is not a one hour reminder. withoutOverlapping
 * because a slow run must not have a second one start behind it and race for
 * the same rows — the dispatcher claims each reminder atomically as well, so
 * this is the cheap guard rather than the only one.
 */
Schedule::command('reminders:send')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
