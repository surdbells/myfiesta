<?php

namespace App\Services\Sharing;

use App\Models\Code;
use App\Models\Event;
use App\Models\Order;
use App\Models\ShareLink;
use App\Models\Ticket;
use App\Services\Checkout\TurnedAway;
use App\Services\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Who has a friend's link to which night, and what it is.
 *
 * Issued to a buyer once their order is paid (IssueShareLink, on OrderPaid),
 * and to anybody signed in on the phone holding a live ticket who asks
 * (ShareLinkController). Because the listener is queued, the tickets email
 * and the tickets page can arrive first; they ask forOrder() too, which makes
 * the link if it is not there yet. Asking twice, from anywhere, gets the same
 * link.
 *
 * The link is the event page with ?ref=, the same parameter a promoter's link
 * uses, so the site, the phone's deep links and checkout carry it as they
 * already carry a promoter's.
 */
class ShareLinks
{
    /** A ticket that will still get its holder in: what a link is handed out against. */
    public const COMING = ['valid', 'checked_in'];

    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * What a night's offer takes off now, in basis points, or null for none.
     *
     * The organizer's figure, held to the platform's largest friend discount
     * (share_max_bps), which staff can lower under offers already running:
     * those are held to it from the next sale on, and the cap at 0 switches
     * every offer off. Everything a buyer reads or pays goes by this.
     *
     * None once the night's online sales are over (TurnedAway::pastSelling):
     * a link is an invitation to buy, and nobody should be asked to send one
     * for a night their friend can no longer buy. The console still shows the
     * offer as it was set (ShareOffers::forOrganizer).
     */
    public function bpsFor(Event $event): ?int
    {
        if (! ShareOffers::offered($event) || TurnedAway::pastSelling($event, now())) {
            return null;
        }

        $max = $this->settings->shareMaxBps();

        return $max > 0 ? min((int) $event->share_discount_bps, $max) : null;
    }

    /**
     * A paid order's buyer's link, made if need be. Null where the night has
     * no offer, the order is not paid, or nobody gave an address.
     */
    public function forOrder(Order $order): ?ShareLink
    {
        if (! in_array($order->status, Code::PAID_STATUSES, true) || blank($order->buyer_email)) {
            return null;
        }

        $event = $order->event;

        if ($event === null || $this->bpsFor($event) === null) {
            return null;
        }

        return $this->forHolder($event, (string) $order->buyer_email, $order->user_id, $order->id);
    }

    /**
     * Somebody's link to a night, made if they have none.
     *
     * Made with ON CONFLICT DO NOTHING and read back, rather than checked and
     * then made: the queued listener and the tickets email can ask in the
     * same second, and both must come away with the one link.
     */
    public function forHolder(Event $event, string $email, ?string $userId = null, ?string $orderId = null): ShareLink
    {
        $address = Str::lower(trim($email));

        $existing = $this->find($event, $address);

        if ($existing !== null) {
            // Somebody who bought as a guest and has since signed in on the
            // phone: the link is theirs either way, and now it knows.
            if ($existing->user_id === null && $userId !== null) {
                $existing->forceFill(['user_id' => $userId])->save();
            }

            return $existing;
        }

        // Three goes, for the vanishing chance that a new slug is taken.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            DB::table('share_links')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'event_id' => $event->id,
                'organization_id' => $event->organization_id,
                'owner_email' => $address,
                'user_id' => $userId,
                'order_id' => $orderId,
                'slug' => ShareLink::newSlug(),
                'reward_count' => 0,
                'created_at' => now(),
            ]);

            $made = $this->find($event, $address);

            if ($made !== null) {
                return $made;
            }
        }

        throw new RuntimeException('A share link could not be made.');
    }

    public function find(Event $event, string $address): ?ShareLink
    {
        return ShareLink::query()
            ->where('event_id', $event->id)
            ->where('owner_email', Str::lower(trim($address)))
            ->first();
    }

    /**
     * The link a ref names on this night, while the night has an offer and
     * the link's holder is still coming.
     *
     * Null for anything else — a promoter's slug, a link to another night, a
     * link whose night has since ended its offer, a link whose holder has
     * been refunded or has passed their ticket on — so the ref goes on to be
     * tried as a promoter's, as it always was.
     */
    public function live(Event $event, ?string $ref): ?ShareLink
    {
        if (! ShareLink::looksLikeOne($ref) || $this->bpsFor($event) === null) {
            return null;
        }

        $link = ShareLink::query()
            ->where('event_id', $event->id)
            ->where('slug', Str::lower(trim((string) $ref)))
            ->first();

        return $link !== null && $this->stillComing($link) ? $link : null;
    }

    /**
     * Whether a link's holder still has a ticket to its night that will get
     * them in.
     *
     * A link is handed to people coming (ShareLinkController asks the same
     * of somebody asking for one), and it should not outlive that: somebody
     * who bought, was given a link and then got their money back would
     * otherwise go on giving away the organizer's discount, and earning codes
     * for it, for a night they are not at. By the address the link was made
     * for, or by the account, for a ticket whose address has since changed.
     */
    public function stillComing(ShareLink $link): bool
    {
        return Ticket::query()
            ->where('event_id', $link->event_id)
            ->whereIn('status', self::COMING)
            ->where(fn ($query) => $query
                ->whereRaw('lower(owner_email) = ?', [$link->owner_email])
                ->when($link->user_id !== null, fn ($q) => $q->orWhere('owner_user_id', $link->user_id)))
            ->exists();
    }

    /** A percentage in basis points, as a person says it: 1500 as "15", 1250 as "12.5". */
    public static function percent(int $bps): string
    {
        return rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');
    }

    /** The address to send: the event page, carrying the link on ?ref=. */
    public function url(ShareLink $link, Event $event): string
    {
        return rtrim((string) config('app.public_url'), '/').'/'.$event->slug.'?ref='.$link->slug;
    }

    /**
     * A link as a ticket carries it (ShareLink in the contract). Without a
     * link — a ticket somebody passed on, whose holder has not asked for one
     * yet — `url` is null and the phone offers to make one.
     *
     * @return array<string, mixed>
     */
    public function present(?ShareLink $link, Event $event, ?string $organizer): array
    {
        $max = (int) $event->share_max_rewards;
        $earned = (int) ($link->reward_count ?? 0);

        return [
            'url' => $link === null ? null : $this->url($link, $event),
            'discount_bps' => (int) $this->bpsFor($event),
            'rewards_earned' => $earned,
            'rewards_left' => max(0, $max - $earned),
            'organizer' => $organizer,
        ];
    }
}
