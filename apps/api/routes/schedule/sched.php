<?php

// Nights that go on sale at a time the organizer chose (events:go-live).

use Illuminate\Support\Facades\Schedule;

/*
 * Every minute: "on sale at 10am" means 10am to the people refreshing the
 * page for it. withoutOverlapping so a slow run does not have a second start
 * behind it; two that did would still meet at each night's lock and send
 * nothing twice (ScheduledGoLive).
 */
Schedule::command('events:go-live')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
