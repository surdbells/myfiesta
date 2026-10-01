<?php

namespace App\Services\Sharing;

use App\Mail\ShareRewardMail;
use App\Models\Code;
use App\Models\Event;
use App\Models\Order;
use App\Models\ShareLink;
use App\Models\ShareReward;
use App\Services\Checkout\CheckoutService;
use App\Services\Settings\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The second half of "both save": what a link's holder is given when a friend
 * pays through it.
 *
 * A single-use code off their next tickets from the same organizer, at the
 * same percentage the friend saved, for a year. Organization-wide, because the
 * point is to bring them back, and the night they shared may be over by the
 * time they spend it. Up to the night's share_max_rewards per link, so a
 * buyer sending their link to their own second address gains little and the
 * organizer's cost has a ceiling.
 *
 * Given once per friend's order: a payment announced twice finds the reward
 * already there (and the unique index on friend_order_id holds even when two
 * deliveries race). Taken back if the friend's order is refunded in full
 * before the code is spent; a spent one stands.
 */
class ShareRewards
{
    /** How long a reward can be spent for. */
    public const VALID_FOR_MONTHS = 12;

    public function __construct(private readonly PlatformSettings $settings) {}

    /**
     * Reward the holder of the link a paid order came through. Null when
     * there is nothing to give: no link, already given, the cap reached, or
     * an order the link took nothing off.
     */
    public function rewardFor(Order $order): ?ShareReward
    {
        if ($order->share_link_id === null) {
            return null;
        }

        $reward = DB::transaction(function () use ($order) {
            // The link first, locked: two friends paying in the same second
            // must not both find the last reward under the cap.
            /** @var ShareLink|null $link */
            $link = ShareLink::query()->whereKey($order->share_link_id)->lockForUpdate()->first();

            if ($link === null || ShareReward::query()->where('friend_order_id', $order->id)->exists()) {
                return null;
            }

            // The order locked too, as a refund locks it (RefundService): a
            // refund landing now either commits first, and is read here as
            // refunded, or waits for this reward to exist, and voids it.
            /** @var Order|null $paid */
            $paid = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($paid === null || ! in_array($paid->status, Code::PAID_STATUSES, true)) {
                return null;
            }

            // Both save, or neither is rewarded: a free ticket taken through
            // a link saved the friend nothing, and rewarding it would make
            // codes the organizer pays for out of orders that cost nobody
            // anything.
            if ((int) $paid->discount_amount <= 0) {
                return null;
            }

            // Checkout refuses somebody using their own link; this is the
            // same rule held where the money is given out.
            if (Str::lower((string) $paid->buyer_email) === $link->owner_email) {
                return null;
            }

            /** @var Event $event */
            $event = Event::query()->whereKey($link->event_id)->firstOrFail();

            $standing = ShareReward::query()
                ->where('share_link_id', $link->id)
                ->where('status', ShareReward::ISSUED)
                ->count();

            if ($standing >= max(1, (int) $event->share_max_rewards)) {
                return null;
            }

            $bps = $this->percentFor($paid);

            if ($bps === null) {
                return null;
            }

            $code = Code::create([
                'organization_id' => $link->organization_id,
                'event_id' => null,
                'code' => $this->freshName($link->organization_id),
                'label' => 'Friend reward',
                'discount_type' => 'percentage',
                'discount_value' => $bps,
                'max_redemptions' => 1,
                'ends_at' => now()->addMonthsNoOverflow(self::VALID_FOR_MONTHS),
                'is_active' => true,
                'purpose' => Code::SHARE_REWARD,
            ]);

            $reward = ShareReward::create([
                'share_link_id' => $link->id,
                'friend_order_id' => $paid->id,
                'code_id' => $code->id,
                'status' => ShareReward::ISSUED,
            ]);

            $link->forceFill(['reward_count' => $standing + 1])->save();

            return $reward;
        });

        if ($reward !== null) {
            $reward->load(['link.event.organization', 'code']);

            Mail::to($reward->link->owner_email)->send(new ShareRewardMail($reward));
        }

        return $reward;
    }

    /**
     * Take back the rewards a friend's order earned, now it has been refunded
     * in full — each one whose code has not been spent. Returns how many were
     * taken back.
     *
     * A code part-way through a checkout is switched off all the same, and
     * the reward with it. Nothing comes back to look again once that checkout
     * lapses, so leaving it on would leave a code to spend for an order that
     * got all its money back. The checkout already under way keeps the price
     * it was given: the code is single-use, so if that one is paid it was the
     * use, and if it lapses there is nothing left to spend.
     */
    public function voidFor(Order $order): int
    {
        $voided = 0;

        $rewards = ShareReward::query()
            ->where('friend_order_id', $order->id)
            ->where('status', ShareReward::ISSUED)
            ->get();

        foreach ($rewards as $reward) {
            $voided += DB::transaction(function () use ($reward) {
                // The code locked as checkout locks it (CheckoutService::redeem),
                // so a checkout starting from now on finds it off.
                /** @var Code|null $code */
                $code = Code::query()->whereKey($reward->code_id)->lockForUpdate()->first();

                if ($code !== null && $code->redemption_count > 0) {
                    return 0;
                }

                $code?->forceFill(['is_active' => false])->save();
                $reward->forceFill(['status' => ShareReward::VOIDED])->save();

                ShareLink::query()->whereKey($reward->share_link_id)->update([
                    'reward_count' => ShareReward::query()
                        ->where('share_link_id', $reward->share_link_id)
                        ->where('status', ShareReward::ISSUED)
                        ->count(),
                ]);

                return 1;
            });
        }

        return $voided;
    }

    /**
     * The friend's saving, which is what the holder is given back: the figure
     * checkout priced the friend's order at, kept on the order when it was
     * placed (CheckoutService), so an offer changed while the friend was
     * paying changes neither side of it. Held to the platform's cap as it
     * stands; a cap switched to 0 since the friend paid takes nothing from a
     * reward already earned.
     */
    private function percentFor(Order $order): ?int
    {
        $bps = $order->pricing_snapshot[CheckoutService::FRIEND_DISCOUNT_BPS] ?? null;

        if (! is_int($bps) || $bps <= 0) {
            return null;
        }

        $max = $this->settings->shareMaxBps();

        return $max > 0 ? min($bps, $max) : $bps;
    }

    /** A name for a reward nobody else in the organization has. */
    private function freshName(string $organizationId): string
    {
        do {
            $name = 'THANKS-'.Str::upper(Str::random(8));
        } while (Code::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('upper(code) = ?', [$name])
            ->exists());

        return $name;
    }
}
