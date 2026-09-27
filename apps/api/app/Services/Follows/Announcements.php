<?php

namespace App\Services\Follows;

use App\Mail\EventAnnouncedMail;
use App\Models\EmailPreference;
use App\Models\Event;
use App\Models\OrganizationFollow;
use Illuminate\Support\Facades\Mail;

/**
 * Telling an organizer's followers that they have announced a night.
 *
 * The payoff of following somebody, and the reason the follow list is a list
 * of organizations rather than a flag on a profile.
 *
 * Email rather than push: push needs certificates this repository does not
 * have, and a mailbox reaches somebody who installed the app once in June and
 * has not opened it since — which is most of a following.
 *
 * Once per event, whatever happens afterwards. Unpublishing to fix a typo and
 * publishing again is a normal afternoon, and every one of those would
 * otherwise be another email to everybody.
 */
class Announcements
{
    /**
     * @return int how many people were told
     */
    public function announce(Event $event): int
    {
        if (! $this->worthAnnouncing($event)) {
            return 0;
        }

        // Claimed before sending, not after. A queue worker that dies halfway
        // through should leave some people untold rather than tell everybody
        // twice — the second is the one people notice.
        $event->forceFill(['announced_at' => now()])->save();

        $event->loadMissing(['organization', 'venue']);

        $sent = 0;

        OrganizationFollow::query()
            ->where('organization_id', $event->organization_id)
            ->with('user')
            ->chunkById(200, function ($follows) use ($event, &$sent) {
                $emails = $follows
                    ->map(fn (OrganizationFollow $follow) => $follow->user?->email)
                    ->filter()
                    ->all();

                $allowed = array_flip(EmailPreference::marketable($emails));

                foreach ($follows as $follow) {
                    $email = $follow->user?->email;

                    // No address, or a deleted account, or somebody who has
                    // said they want no mail like this from anyone.
                    if ($email === null || ! isset($allowed[EmailPreference::normalise($email)])) {
                        continue;
                    }

                    Mail::to($email)->queue(new EventAnnouncedMail($event, $follow, $email));
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * An announcement is for a night somebody could actually go to.
     *
     * Invitation events are reachable only by their guests through a token, so
     * announcing one to a following would be the platform handing out the
     * guest list to a wedding.
     */
    private function worthAnnouncing(Event $event): bool
    {
        return $event->status === 'published' && $this->wouldAnnounce($event);
    }

    /**
     * Whether publishing this event now would tell its organizer's followers.
     *
     * Asked before the publish as well as during it: staff acting as an
     * organization do not send mail in its name (WhileImpersonating), and
     * this is the one publish that does.
     */
    public function wouldAnnounce(Event $event): bool
    {
        return $event->announced_at === null
            && $event->kind === 'ticketed'
            && $event->starts_at->isFuture();
    }
}
