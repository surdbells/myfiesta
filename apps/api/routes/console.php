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

/*
 * Repeating events.
 *
 * Daily, because the window is measured in months — nobody is selling tickets
 * to a night six months out that appeared six hours late. Overnight, when the
 * duplication work is cheapest.
 */
Schedule::command('series:extend')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Abandoned checkouts.
 *
 * Every fifteen minutes, so an organizer's order list stops showing a basket
 * as "Confirming" soon after it is plainly not coming — see
 * AbandonedCheckouts for why nothing else ever closed them.
 */
Schedule::command('checkouts:expire')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Webhooks.
 *
 * Retries every minute: the backoff is written on each delivery, so this only
 * picks up the ones that are due. Deliveries are pruned nightly because each
 * one carries a buyer's name and address — see WebhookDelivery::KEEP_DAYS.
 */
Schedule::command('webhooks:retry')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('webhooks:prune')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Campaigns an organizer scheduled. Every five minutes: "Friday at 6pm" going
 * out at 6:04 is on time as far as anybody reading it can tell, and the list
 * is worked out when it sends, not when it was written.
 */
Schedule::command('campaigns:send')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
