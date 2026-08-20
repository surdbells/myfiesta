<?php

namespace App\Services\Reminders;

use App\Mail\EventReminderMail;
use App\Models\EmailPreference;
use App\Models\EventReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sending the reminders that are due, and only those.
 *
 * Three rules do most of the work here.
 *
 * A reminder is never sent twice to the same person. Four hundred recipients is
 * four hundred queued messages, and a worker that dies after two hundred has to
 * resume rather than restart — so each delivery is recorded before it is
 * queued, and the record is what decides.
 *
 * A reminder that is late by more than its own window is dropped, not sent. If
 * the queue is down for a day, the "starts in a week" email must not arrive the
 * morning of the event saying the event is a week away. Silence is the better
 * failure.
 *
 * Anyone who has opted out is filtered before anything is composed, not after.
 * A message that gets built and then discarded is one refactor away from being
 * built and then sent.
 */
class ReminderDispatcher
{
    /**
     * Send everything due now.
     *
     * @return int reminders processed
     */
    public function dispatchDue(): int
    {
        $candidates = EventReminder::query()
            ->where('status', 'scheduled')
            ->whereHas('event', fn ($q) => $q
                ->where('status', 'published')
                ->where('starts_at', '>', now()))
            ->with('event')
            ->get()
            // Filtered in PHP rather than SQL because send_at is derived from
            // the event's start and the offset. Computing it in SQL would mean
            // the definition living in two places, and the copy in the query is
            // the one that goes stale.
            ->filter(fn (EventReminder $r) => $r->sendAt()->isPast());

        $processed = 0;

        foreach ($candidates as $reminder) {
            if ($this->send($reminder)) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * @return bool whether anything was actually sent
     */
    public function send(EventReminder $reminder): bool
    {
        // Claimed with a conditional update, so two workers running at once
        // cannot both decide this one is theirs. The row count is the lock.
        $claimed = EventReminder::query()
            ->whereKey($reminder->id)
            ->where('status', 'scheduled')
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $reminder->refresh();

        if ($this->tooLate($reminder)) {
            // Dropped rather than sent. An email saying an event is a week away
            // that lands the morning of it is worse than no email, because the
            // reader now distrusts every future one.
            Log::warning('Reminder skipped as stale', [
                'reminder_id' => $reminder->id,
                'event_id' => $reminder->event_id,
                'due' => $reminder->sendAt()->toIso8601String(),
            ]);

            $reminder->update(['status' => 'cancelled', 'recipients' => 0]);

            return false;
        }

        $recipients = $this->recipientsFor($reminder);
        $sent = 0;

        foreach ($recipients as $email) {
            // Recorded first. If this insert conflicts, somebody has already
            // had this reminder and the send is skipped — which is what makes
            // a resumed run safe.
            $claimedRecipient = DB::table('reminder_deliveries')->insertOrIgnore([
                'reminder_id' => $reminder->id,
                'email' => $email,
                'created_at' => now(),
            ]);

            if ($claimedRecipient === 0) {
                continue;
            }

            $preference = EmailPreference::forEmail($email);

            Mail::to($email)->queue(new EventReminderMail($reminder, $preference));
            $sent++;
        }

        $reminder->update([
            'status' => 'sent',
            'sent_at' => now(),
            // Counts this run only. A resumed run adds nothing, which is the
            // honest number — the previous run already counted those.
            'recipients' => ($reminder->recipients ?? 0) + $sent,
        ]);

        return $sent > 0;
    }

    /**
     * Everybody holding a live ticket, minus everybody who said no.
     *
     * Read from tickets rather than orders: a ticket transferred to somebody
     * else should remind the person who now holds it, and a refunded ticket
     * should remind nobody.
     */
    private function recipientsFor(EventReminder $reminder): array
    {
        $emails = $reminder->event
            ->tickets()
            ->whereIn('status', ['valid', 'checked_in'])
            ->pluck('owner_email')
            ->all();

        return EmailPreference::remindable($emails);
    }

    /**
     * Late enough that the message would be wrong.
     *
     * The window scales with the offset: a "one week before" reminder is still
     * roughly true a few hours late, and a "one hour before" reminder is not.
     * Capped at a day so a very long lead time cannot excuse a very long delay.
     */
    private function tooLate(EventReminder $reminder): bool
    {
        $grace = min((int) ($reminder->offset_minutes * 0.25), 1440);

        return $reminder->sendAt()->addMinutes(max($grace, 15))->isPast();
    }
}
