<?php

// Surveys sent once a night is over (surveys:send-due).

use Illuminate\Support\Facades\Schedule;

/*
 * Hourly: a night's survey goes 18 hours after it ends unless its organizer
 * chose otherwise, and an hour either side of that is still the morning
 * after. Each invitation is claimed before its email is queued
 * (SurveySender), so withoutOverlapping is the cheap guard, not the only one.
 */
Schedule::command('surveys:send-due')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
