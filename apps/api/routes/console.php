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
 * Refunds the payment processor never answered. Left waiting rather than
 * called failed, because the money may already have gone back — and without
 * this they would wait for ever, holding their tickets. Every five minutes;
 * each refund is only asked about once it has waited ten (RefundService).
 */
Schedule::command('refunds:follow-up')
    ->everyFiveMinutes()
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

/*
 * Privacy requests: an export is deleted a week after it is made, and a
 * request nobody proved is closed after a day. Both are personal data with a
 * shelf life, so neither waits on anybody remembering.
 */
Schedule::command('privacy:prune')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Staff sessions that ran out on their own. The token has already stopped
 * working by then — this only writes the end into the audit trail, so a
 * session never reads as still open to somebody going through it.
 */
Schedule::command('impersonation:close-lapsed')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Heartbeats, for the readiness check (GET /api/health/ready). The scheduler
 * writes its own time every minute and sends the queue a job that writes the
 * worker's; either going stale is the first sign that emails have stopped.
 * In the foreground: it takes a millisecond, and a heartbeat that ran in a
 * process of its own would say the scheduler was fine when it was only the
 * fork that was. No overlap lock: two at once do no harm, and a lock left by
 * a killed run would silence the heartbeat for a day. Through maintenance
 * mode as well: the scheduler is still alive while the platform is down, and
 * a switch-over is checked before `up`. (Workers take no jobs while it is
 * down, so the queue's heartbeat does go stale then; they catch up after.)
 */
Schedule::command('app:heartbeat')
    ->everyMinute()
    ->evenInMaintenanceMode();

/*
 * The nightly backup (docs/OPERATIONS.md). 06:30 UTC is 02:30 in Toronto in
 * summer (01:30 in winter) and 07:30 in Lagos: after most doors have closed
 * in one market and before the day's sales start in the other. pg_dump reads a snapshot and blocks
 * nobody, so this is about load, not locks. Sentry is told when it starts and
 * how it ended, and says so when a night goes by without one.
 */
Schedule::command('backup:run')
    ->dailyAt('06:30')
    ->withoutOverlapping(6 * 60)
    ->runInBackground()
    ->sentryMonitor('database-backup');

/*
 * Things that were only ever waiting, deleted once they cannot be used —
 * each carries somebody's details, and none of it is a record of anything.
 *
 *   failed jobs    a job's payload is the job, buyer's name and address
 *                  included; kept long enough to retry after a weekend
 *   sign-in tokens past their expiry (a week of grace, for "last active")
 *   password resets past their hour
 *   address changes and sign-ups whose links ran out (app:prune-expired)
 *   anything a model marks Prunable (model:prune; none do yet)
 *
 * Stock holds are swept by checkouts:expire, webhook deliveries by
 * webhooks:prune and lapsed staff sessions by impersonation:close-lapsed,
 * above. Nothing here touches the ledger, the audit trail, orders, tickets
 * or scans: those are records, the first two are append-only in the
 * database itself, and PruneScheduleTest holds all of it in place.
 */
Schedule::command('queue:prune-failed', ['--hours' => 24 * config('operations.prune.failed_jobs_days')])
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('sanctum:prune-expired', ['--hours' => config('operations.prune.tokens_expired_hours')])
    ->dailyAt('05:05')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('auth:clear-resets')
    ->dailyAt('05:10')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('app:prune-expired')
    ->dailyAt('05:15')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('model:prune')
    ->dailyAt('05:20')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Answering a chargeback (config/disputes.php).
 *
 * The processor's own record of each payment, asked for after the payment
 * rather than during it, so a slow processor never holds up somebody's
 * tickets: every five minutes, trying each again on a widening gap.
 *
 * Each night that is over, written down once from its door where nobody can
 * edit it. Hourly; a night is only due half a day after its door closed, so
 * the offline phones' scans are in.
 *
 * And, 18 months after each night, the address and browser on its orders,
 * its ticket history and its payment records let go — past every card
 * network's window for a dispute. Orders, tickets, scans, the ledger and the
 * audit trail are records and it never touches them (DisputeEvidenceRetentionTest).
 */
Schedule::command('disputes:collect-evidence')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('disputes:record-completions')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('disputes:prune-evidence')
    ->dailyAt('05:25')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Chargebacks nobody has answered: Admin and Finance are emailed with five
 * days left and again with two, each once (DisputeDesk::remindDue). Hourly,
 * so a deadline is never met by a reminder a day late.
 */
Schedule::command('disputes:remind')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
