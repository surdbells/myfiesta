<?php

namespace App\Services\Messaging;

use App\Mail\AttendeeMessage;
use App\Models\EmailPreference;
use App\Models\EventMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sending one message to everyone holding a ticket.
 *
 * Deliberately the same shape as the reminder dispatcher, and for the same
 * reasons: a delivery is recorded before it is queued, so a worker that dies
 * halfway resumes instead of emailing the first two hundred people twice, and
 * the audience is filtered before anything is composed rather than after.
 */
class MessageSender
{
    /**
     * @return int how many were sent this run
     */
    public function send(EventMessage $message): int
    {
        // Claimed with a conditional update so two workers cannot both decide
        // this message is theirs. The row count is the lock.
        $claimed = EventMessage::query()
            ->whereKey($message->id)
            ->whereIn('status', ['queued', 'failed'])
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if ($claimed === 0) {
            return 0;
        }

        $message->refresh()->load('event.organization');

        $holders = $this->holders($message);
        $audience = $message->important
            // An important message reaches everybody. Somebody who opted out of
            // being reminded still needs to know the venue moved, and the email
            // says why it arrived anyway.
            ? $holders
            : EmailPreference::remindable($holders);

        $sent = 0;

        foreach ($audience as $email) {
            $claimedRecipient = DB::table('event_message_deliveries')->insertOrIgnore([
                'message_id' => $message->id,
                'email' => $email,
                'created_at' => now(),
            ]);

            if ($claimedRecipient === 0) {
                continue;
            }

            Mail::to($email)->queue(new AttendeeMessage($message, EmailPreference::forEmail($email)));
            $sent++;
        }

        $message->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipients' => ($message->recipients ?? 0) + $sent,
            // Recorded so an organizer can see the gap between who holds a
            // ticket and who was written to, rather than wondering why the
            // count is lower than their guest list.
            'suppressed' => count($holders) - count($audience),
        ]);

        return $sent;
    }

    /**
     * Everybody with a live ticket to this event.
     *
     * Read from tickets rather than orders, so a transferred ticket reaches
     * whoever holds it now and a refunded one reaches nobody — being emailed
     * about a night you were refunded for is the worst message this could send.
     *
     * @return list<string>
     */
    private function holders(EventMessage $message): array
    {
        return $message->event
            ->tickets()
            ->whereIn('status', ['valid', 'checked_in'])
            ->pluck('owner_email')
            ->map(fn (string $email) => EmailPreference::normalise($email))
            ->unique()
            ->values()
            ->all();
    }
}
