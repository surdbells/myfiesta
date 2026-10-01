<?php

namespace App\Services\Sharing;

use App\Models\Code;
use App\Models\Event;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A night's friend discount, as its organizer sets it.
 *
 * The offer lives on the event (share_discount_bps, share_max_rewards) and
 * works through one hidden code of the event's own (purpose share_friend):
 * pricing, the ledger and the sales report then treat a friend's discount as
 * the discount it is, charged to the organizer like any other, without a
 * second idea of what a discount is. The code is made the first time an
 * offer is set, changed with it, and turned off — never deleted — when the
 * offer ends, because the orders that used it still point at it.
 */
class ShareOffers
{
    public function __construct(private readonly Auditor $auditor) {}

    /** Whether a night offers a friend discount at all. */
    public static function offered(Event $event): bool
    {
        return $event->share_discount_bps !== null && (int) $event->share_discount_bps > 0;
    }

    /** The hidden code behind a night's offer, on or off, or null if it never had one. */
    public function friendCode(Event $event): ?Code
    {
        return Code::query()
            ->where('event_id', $event->id)
            ->where('purpose', Code::SHARE_FRIEND)
            ->first();
    }

    /**
     * Start, change or end a night's offer, and its code with it.
     *
     * The bounds (the platform's cap, at least one reward) are the caller's
     * to check, where they can be said as a sentence; the database holds the
     * rest.
     */
    public function set(Event $event, ?int $discountBps, int $maxRewards, ?User $by = null): Event
    {
        return DB::transaction(function () use ($event, $discountBps, $maxRewards, $by) {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $before = [
                'discount_bps' => $locked->share_discount_bps === null ? null : (int) $locked->share_discount_bps,
                'max_rewards' => (int) $locked->share_max_rewards,
            ];

            $locked->forceFill([
                'share_discount_bps' => $discountBps,
                'share_max_rewards' => $maxRewards,
            ])->save();

            $this->syncCode($locked);

            $this->auditor->record(
                $discountBps === null ? 'share_offer.ended' : 'share_offer.set',
                $locked,
                $by,
                metadata: [
                    'from' => $before,
                    'to' => ['discount_bps' => $discountBps, 'max_rewards' => $maxRewards],
                ],
            );

            return $locked;
        });
    }

    /**
     * Make the hidden code agree with the offer on the event.
     *
     * Also what a copy of a night calls (Copying\Share), so a copied offer has
     * a code of its own rather than the original's, which is scoped to the
     * original and would never apply.
     */
    public function syncCode(Event $event): void
    {
        $code = $this->friendCode($event);

        if (! self::offered($event)) {
            if ($code !== null && $code->is_active) {
                $code->forceFill(['is_active' => false])->save();
            }

            return;
        }

        $bps = (int) $event->share_discount_bps;

        if ($code === null) {
            Code::create([
                'organization_id' => $event->organization_id,
                'event_id' => $event->id,
                // Never shown and never typed (Code::scopeTypeable), but a code
                // needs a name, and one an organizer would not pick for theirs.
                'code' => 'FRIEND-'.Str::upper(Str::random(10)),
                'label' => 'Friend’s discount',
                'discount_type' => 'percentage',
                'discount_value' => $bps,
                'is_active' => true,
                'purpose' => Code::SHARE_FRIEND,
            ]);

            return;
        }

        $code->forceFill([
            'discount_type' => 'percentage',
            'discount_value' => $bps,
            'discount_currency' => null,
            'is_active' => true,
        ])->save();
    }

    /**
     * The offer as the console shows it, with what it has done so far.
     *
     * Always there, with `discount_bps` null while the night offers nothing:
     * the console needs the platform's cap to start one, and a night whose
     * offer has ended still shows what it did while it ran.
     *
     * @return array<string, mixed>
     */
    public function forOrganizer(Event $event, int $maxBps): array
    {
        $links = DB::table('share_links')->where('event_id', $event->id);

        return [
            'discount_bps' => self::offered($event) ? (int) $event->share_discount_bps : null,
            'max_rewards' => (int) $event->share_max_rewards,
            // The most the platform lets any night offer, for the form.
            'max_bps' => $maxBps,
            'links' => (clone $links)->count(),
            'friend_orders' => DB::table('orders')
                ->where('event_id', $event->id)
                ->whereNotNull('share_link_id')
                ->whereIn('status', Code::PAID_STATUSES)
                ->count(),
            'rewards' => DB::table('share_rewards')
                ->whereIn('share_link_id', (clone $links)->select('id'))
                ->where('status', 'issued')
                ->count(),
        ];
    }
}
