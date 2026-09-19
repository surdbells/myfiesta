<?php

namespace App\Services\Campaigns;

use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sending one campaign.
 *
 * The same shape as MessageSender: claimed with a conditional update so two
 * workers cannot both send it, each address recorded before its email is
 * queued so a worker that dies halfway resumes rather than repeats, and the
 * list worked out at the moment of sending — not when it was written — so
 * somebody who unsubscribed or bought a ticket in between is not written to.
 */
class CampaignSender
{
    public const STALE_MINUTES = 15;

    public function __construct(
        private readonly Audiences $audiences,
        private readonly Auditor $auditor,
    ) {}

    /** @return int how many were written to this run */
    public function send(Campaign $campaign): int
    {
        $claimed = Campaign::query()
            ->whereKey($campaign->id)
            ->where(fn ($q) => $q
                ->where(fn ($due) => $due->where('status', 'scheduled')->where('scheduled_for', '<=', now()))
                // A send whose worker died partway. Taken up again: every
                // address already written to is recorded, so nobody gets two.
                ->orWhere(fn ($stale) => $stale->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))))
            ->update(['status' => 'sending', 'updated_at' => now()]);

        if ($claimed === 0) {
            return 0;
        }

        $campaign->refresh()->load(['organization', 'event.venue', 'author']);

        // A night that was cancelled or taken down since this was written is
        // not one to sell. Stopped, and said so on the campaign.
        if ($campaign->event !== null && ($campaign->event->status !== 'published' || $campaign->event->starts_at->isPast())) {
            $campaign->update(['status' => 'cancelled']);

            return 0;
        }

        ['all' => $all, 'reachable' => $reachable] = $this->audiences->resolve(
            $campaign->organization_id,
            $campaign->audience,
            $campaign->event,
        );

        $sent = 0;

        foreach ($reachable as $email) {
            $claimedRecipient = DB::table('campaign_deliveries')->insertOrIgnore([
                'campaign_id' => $campaign->id,
                'organization_id' => $campaign->organization_id,
                'email' => $email,
                'created_at' => now(),
            ]);

            if ($claimedRecipient === 0) {
                continue;
            }

            Mail::to($email)->queue(new CampaignMail($campaign, $email));
            $sent++;
        }

        // Counted from what was recorded rather than from this run, so a send
        // that was resumed still reports everybody it reached.
        $recipients = DB::table('campaign_deliveries')->where('campaign_id', $campaign->id)->count();

        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipients' => $recipients,
            'suppressed' => max(0, count($all) - $recipients),
        ]);

        // Written to the trail whether it went now or on a schedule: mail to
        // hundreds of people in the organization's name is a thing somebody
        // may later need to show was done, by whom, and to how many.
        $this->auditor->record('campaign.sent', $campaign, $campaign->author, $campaign->organization_id, metadata: [
            'audience' => $campaign->audience,
            'recipients' => $recipients,
            'suppressed' => max(0, count($all) - $recipients),
        ]);

        return $sent;
    }
}
