<?php

namespace App\Mail;

use App\Models\ShareReward;
use App\Services\Sharing\ShareLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "A friend bought through your link": the reward code, to the link's holder.
 *
 * Names nobody. The holder sent the link to whoever they sent it to, and the
 * friend bought a ticket, not a mention in somebody else's inbox — so it says
 * that a friend bought, for which night, and what the holder now has.
 */
class ShareRewardMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ShareReward $reward) {}

    public function envelope(): Envelope
    {
        $organizer = $this->reward->link->event->organization->name;

        return new Envelope(subject: "A friend used your link: {$this->percent()}% off your next tickets from {$organizer}");
    }

    public function content(): Content
    {
        $event = $this->reward->link->event;
        $organization = $event->organization;
        $code = $this->reward->code;

        return new Content(
            markdown: 'mail.share-reward',
            with: [
                'event' => $event,
                'organizer' => $organization->name,
                'code' => $code->code,
                'percent' => $this->percent(),
                'expires' => $code->ends_at?->timezone($event->timezone)->format('j F Y'),
                'url' => rtrim((string) config('app.public_url'), '/').'/o/'.$organization->slug,
                'left' => max(0, (int) $event->share_max_rewards - (int) $this->reward->link->reward_count),
            ],
        );
    }

    /** The percentage, as a person says it: 15, or 12.5. */
    private function percent(): string
    {
        return ShareLinks::percent((int) $this->reward->code->discount_value);
    }
}
